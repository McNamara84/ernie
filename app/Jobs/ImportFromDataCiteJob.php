<?php

namespace App\Jobs;

use App\Models\Resource;
use App\Services\DataCiteImportService;
use App\Services\DataCiteToResourceTransformer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Background job for importing DOIs from DataCite.
 *
 * Fetches all DOIs from the DataCite API and creates corresponding
 * Resource records in the database. Progress is tracked via Redis
 * cache for real-time frontend updates.
 */
class ImportFromDataCiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     *
     * Import rate is approximately 100-200 DOIs per minute depending on network.
     * For 10,000 DOIs, expect ~60-90 minutes processing time.
     */
    public int $timeout = 7200; // 2 hours

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  int  $userId  The user who initiated the import
     * @param  string  $importId  Unique identifier for progress tracking (UUID format, lowercase)
     *
     * @throws \InvalidArgumentException If importId is not a valid UUID
     */
    public function __construct(
        private int $userId,
        private string $importId
    ) {
        // Validate UUID format to prevent cache key collisions or unexpected behavior.
        // The importId is used as part of the cache key and must be unique.
        // We enforce lowercase UUIDs for consistency (RFC 4122 recommends lowercase).
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $importId)) {
            // Check if it's a valid UUID with uppercase letters
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $importId)) {
                // Normalize to lowercase for consistency
                $this->importId = strtolower($importId);
            } else {
                throw new \InvalidArgumentException(
                    "Invalid importId format. Expected UUID, got: {$importId}"
                );
            }
        }
    }

    /**
     * Execute the job.
     */
    public function handle(
        DataCiteImportService $importService,
        DataCiteToResourceTransformer $transformer
    ): void {
        Log::info('Starting DataCite import job', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
        ]);

        $startTime = now();

        try {
            // Get total count for progress calculation
            $total = $importService->getTotalDoiCount();

            $this->updateProgress([
                'status' => 'running',
                'total' => $total,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'skipped_dois' => [],
                'failed_dois' => [],
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => null,
                'current_prefix' => null,
            ]);

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            /** @var array<int, string> */
            $skippedDois = [];
            /** @var array<int, array{doi: string, error: string}> */
            $failedDois = [];

            // Maximum entries to store in cache (to prevent memory issues)
            $maxStoredDois = 100;

            // Process DOIs one by one using the generator
            // Each DOI is processed in its own transaction for resilience
            foreach ($importService->fetchAllDois() as $doiRecord) {
                $processed++;

                // Check if import was cancelled (at record 1, then every 50 records: 1, 51, 101, ...)
                // Using '% 50 === 1' instead of '% 50 === 0' ensures:
                // 1. Early cancellation detection at the very first record
                // 2. Consistent interval of 50 records between subsequent checks
                // For 10,000 DOIs this results in ~200 cache reads instead of 10,000.
                if ($processed % 50 === 1) {
                    $currentStatus = Cache::get($this->getCacheKey());
                    if (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled') {
                        Log::info('Import cancelled by user', ['import_id' => $this->importId, 'processed' => $processed - 1]);
                        break;
                    }
                }

                $doi = $doiRecord['attributes']['doi'] ?? $doiRecord['id'] ?? null;

                if ($doi === null) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = [
                            'doi' => 'unknown',
                            'error' => 'No DOI found in record',
                        ];
                    }
                    $this->updateProgressCounts($processed, $imported, $skipped, $failed, $skippedDois, $failedDois, $total);

                    continue;
                }

                try {
                    // Use database transaction to ensure atomicity of the check-then-insert operation.
                    // Note: This relies on the database's default isolation level (typically READ COMMITTED)
                    // to prevent duplicate inserts. The DOI column has a unique constraint as additional protection.
                    $result = DB::transaction(function () use ($transformer, $doiRecord, $doi) {
                        // Check inside transaction - unique constraint on DOI provides ultimate protection
                        if (Resource::where('doi', $doi)->exists()) {
                            return 'skipped';
                        }

                        $transformer->transform($doiRecord, $this->userId);

                        return 'imported';
                    });

                    if ($result === 'skipped') {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        Log::debug('Skipping existing DOI', ['doi' => $doi]);
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $skippedDois, $failedDois, $total);
                        continue;
                    }

                    $imported++;

                    Log::debug('Imported DOI', ['doi' => $doi]);

                } catch (\Illuminate\Database\QueryException $e) {
                    // Handle unique constraint violations (race condition: DOI was inserted by another process)
                    // MySQL/MariaDB error code 1062 = Duplicate entry for unique key
                    // SQLite error code 19 with "UNIQUE constraint failed" in message
                    // We must be specific to avoid catching NOT NULL or other integrity constraints
                    $isDuplicateEntry = false;
                    if (isset($e->errorInfo[1])) {
                        // MySQL/MariaDB: error code 1062
                        $isDuplicateEntry = $e->errorInfo[1] === 1062;
                    }
                    // SQLite: check error message for UNIQUE constraint
                    if (! $isDuplicateEntry && str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        $isDuplicateEntry = true;
                    }

                    if ($isDuplicateEntry) {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        Log::debug('Skipping DOI due to concurrent insert (race condition)', ['doi' => $doi]);
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $skippedDois, $failedDois, $total);
                        continue;
                    }

                    // Re-throw other database errors to be caught by the generic handler
                    throw $e;
                } catch (\Exception $e) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = [
                            'doi' => $doi,
                            'error' => $e->getMessage(),
                        ];
                    }

                    Log::warning('Failed to import DOI', [
                        'doi' => $doi,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $skippedDois, $failedDois, $total);
            }

            // Determine final status - preserve 'cancelled' if user cancelled during processing
            $currentStatus = Cache::get($this->getCacheKey());
            $finalStatus = (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled')
                ? 'cancelled'
                : 'completed';

            $this->updateProgress([
                'status' => $finalStatus,
                'total' => $total,
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
                'current_prefix' => null,
            ]);

            Log::info('DataCite import completed', [
                'import_id' => $this->importId,
                'total' => $total,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'duration_seconds' => now()->diffInSeconds($startTime),
            ]);

        } catch (\Exception $e) {
            Log::error('DataCite import job failed', [
                'import_id' => $this->importId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateProgress([
                'status' => 'failed',
                'error' => $e->getMessage(),
                'completed_at' => now()->toIso8601String(),
            ]);

            throw $e;
        }
    }

    /**
     * Update progress counts in cache.
     *
     * @param  array<int, string>  $skippedDois
     * @param  array<int, array{doi: string, error: string}>  $failedDois
     */
    private function updateProgressCounts(
        int $processed,
        int $imported,
        int $skipped,
        int $failed,
        array $skippedDois,
        array $failedDois,
        int $total
    ): void {
        // Only update cache every 50 records to reduce cache load.
        // For 10,000 DOIs this results in ~200 cache writes instead of 10,000.
        // The final state is always written when $processed === $total.
        // When total is a multiple of 50, the last batch update and final update coincide,
        // so no redundant writes occur.
        if ($processed % 50 === 0 || $processed === $total) {
            // Update only the changing keys to avoid unnecessary array copies
            $this->updateProgressKeys([
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
            ]);
        }
    }

    /**
     * Update the progress cache with a complete progress array.
     *
     * Use this for initial setup where all keys are provided.
     *
     * @param  array<string, mixed>  $data  Complete progress data
     */
    private function updateProgress(array $data): void
    {
        Cache::put(
            $this->getCacheKey(),
            $data,
            now()->addHours(24)
        );
    }

    /**
     * Update specific keys in the progress cache.
     *
     * Use this for incremental updates where only some keys change.
     * More efficient than updateProgress when modifying existing state.
     *
     * @param  array<string, mixed>  $data  Keys to update
     */
    private function updateProgressKeys(array $data): void
    {
        $currentProgress = Cache::get($this->getCacheKey(), []);

        // Directly assign new values to avoid array_merge overhead
        foreach ($data as $key => $value) {
            $currentProgress[$key] = $value;
        }

        Cache::put(
            $this->getCacheKey(),
            $currentProgress,
            now()->addHours(24)
        );
    }

    /**
     * Get the cache key for this import.
     */
    private function getCacheKey(): string
    {
        return "datacite_import:{$this->importId}";
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('DataCite import job failed completely', [
            'import_id' => $this->importId,
            'error' => $exception?->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get the import ID.
     */
    public function getImportId(): string
    {
        return $this->importId;
    }
}
