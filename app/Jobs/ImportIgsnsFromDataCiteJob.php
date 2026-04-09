<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\IgsnMetadata;
use App\Models\Resource;
use App\Services\DataCiteToIgsnTransformer;
use App\Services\IgsnEnrichmentService;
use App\Services\IgsnImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Background job for importing IGSNs from the DataCite API.
 *
 * Fetches all IGSNs (prefix 10.60510), creates Resource + IgsnMetadata records,
 * and enriches them with IGSN-specific metadata from Solr/legacy DB.
 * Progress is tracked via Redis cache for real-time frontend updates.
 */
class ImportIgsnsFromDataCiteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Maximum runtime: 4 hours (longer than DOI import due to enrichment).
     */
    public int $timeout = 14400;

    public int $tries = 1;

    /**
     * @param  int  $userId  The user who initiated the import
     * @param  string  $importId  UUID for progress tracking
     */
    public function __construct(
        private int $userId,
        private string $importId
    ) {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $importId)) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $importId)) {
                $this->importId = strtolower($importId);
            } else {
                throw new \InvalidArgumentException(
                    "Invalid importId format. Expected UUID, got: {$importId}"
                );
            }
        }
    }

    public function handle(
        IgsnImportService $importService,
        DataCiteToIgsnTransformer $transformer,
        IgsnEnrichmentService $enrichmentService
    ): void {
        Log::info('Starting IGSN import job', [
            'import_id' => $this->importId,
            'user_id' => $this->userId,
        ]);

        $startTime = now();

        try {
            $total = $importService->getTotalIgsnCount();

            $this->updateProgress([
                'status' => 'running',
                'total' => $total,
                'processed' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
                'enriched' => 0,
                'skipped_dois' => [],
                'failed_dois' => [],
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => null,
            ]);

            $processed = 0;
            $imported = 0;
            $skipped = 0;
            $failed = 0;
            $enriched = 0;
            /** @var array<int, string> */
            $skippedDois = [];
            /** @var array<int, array{doi: string, error: string}> */
            $failedDois = [];
            $maxStoredDois = 100;

            foreach ($importService->fetchAllIgsns() as $igsnRecord) {
                $processed++;

                // Check for cancellation every 50 records
                if ($processed === 1 || $processed % 50 === 0) {
                    $currentStatus = Cache::get($this->getCacheKey());
                    if (isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled') {
                        Log::info('IGSN import cancelled by user', [
                            'import_id' => $this->importId,
                            'processed' => $processed - 1,
                        ]);
                        break;
                    }
                }

                $doi = $igsnRecord['attributes']['doi'] ?? $igsnRecord['id'] ?? null;

                if ($doi === null) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = ['doi' => 'unknown', 'error' => 'No DOI found in record'];
                    }
                    $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                    continue;
                }

                try {
                    $result = DB::transaction(function () use ($transformer, $igsnRecord, $doi) {
                        if (Resource::where('doi', $doi)->exists()) {
                            return ['status' => 'skipped', 'resource' => null];
                        }

                        $resource = $transformer->transform($igsnRecord, $this->userId);

                        return ['status' => 'imported', 'resource' => $resource];
                    });

                    if ($result['status'] === 'skipped') {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                        continue;
                    }

                    $imported++;

                    // Enrich with IGSN-specific metadata (non-critical)
                    /** @var Resource $importedResource */
                    $importedResource = $result['resource'];
                    $igsnMetadata = $importedResource->igsnMetadata;

                    if ($igsnMetadata instanceof IgsnMetadata) {
                        try {
                            $wasEnriched = $enrichmentService->enrich($importedResource, $igsnMetadata);
                            if ($wasEnriched) {
                                $enriched++;
                            }
                        } catch (\Throwable $e) {
                            Log::debug('IGSN enrichment failed (non-critical)', [
                                'doi' => $doi,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                } catch (\Illuminate\Database\QueryException $e) {
                    $isDuplicateEntry = false;
                    if (isset($e->errorInfo[1])) {
                        $isDuplicateEntry = $e->errorInfo[1] === 1062;
                    }
                    if (! $isDuplicateEntry && str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                        $isDuplicateEntry = true;
                    }

                    if ($isDuplicateEntry) {
                        $skipped++;
                        if (count($skippedDois) < $maxStoredDois) {
                            $skippedDois[] = $doi;
                        }
                        $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);

                        continue;
                    }

                    throw $e;
                } catch (\Exception $e) {
                    $failed++;
                    if (count($failedDois) < $maxStoredDois) {
                        $failedDois[] = ['doi' => $doi, 'error' => $e->getMessage()];
                    }

                    Log::warning('Failed to import IGSN', [
                        'doi' => $doi,
                        'error' => $e->getMessage(),
                    ]);
                }

                $this->updateProgressCounts($processed, $imported, $skipped, $failed, $enriched, $skippedDois, $failedDois, $total);
            }

            // Resolve parent-child relationships after all imports (skip if cancelled)
            $currentStatus = Cache::get($this->getCacheKey());
            $wasCancelled = isset($currentStatus['status']) && $currentStatus['status'] === 'cancelled';

            if (! $wasCancelled) {
                $this->resolveParentRelationships();
            }

            $finalStatus = $wasCancelled ? 'cancelled' : 'completed';

            $this->updateProgress([
                'status' => $finalStatus,
                'total' => $total,
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
                'started_at' => $startTime->toIso8601String(),
                'completed_at' => now()->toIso8601String(),
            ]);

            Log::info('IGSN import completed', [
                'import_id' => $this->importId,
                'total' => $total,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'duration_seconds' => now()->diffInSeconds($startTime),
            ]);

        } catch (\Exception $e) {
            Log::error('IGSN import job failed', [
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
     * Resolve parent-child IGSN relationships after all imports.
     *
     * During import, parent_igsn handles are stored in description_json.
     * This pass resolves them to actual parent_resource_id values.
     */
    private function resolveParentRelationships(): void
    {
        Log::info('Resolving IGSN parent-child relationships');

        $resolved = 0;

        IgsnMetadata::query()
            ->whereNull('parent_resource_id')
            ->whereNotNull('description_json')
            ->chunkById(500, function ($records) use (&$resolved): void {
                // Collect all parent handles in this chunk
                /** @var array<string, list<IgsnMetadata>> */
                $handleMap = [];
                foreach ($records as $igsnMeta) {
                    $descJson = $igsnMeta->description_json;
                    if (! is_array($descJson) || ! isset($descJson['parent_igsn_handle'])) {
                        continue;
                    }
                    $handle = strtoupper($descJson['parent_igsn_handle']);
                    $handleMap[$handle][] = $igsnMeta;
                }

                if ($handleMap === []) {
                    return;
                }

                // Bulk-fetch all parent resources for this chunk's handles in one query
                $handles = array_keys($handleMap);

                // Build LIKE conditions to match DOIs ending with each handle (cross-database compatible)
                $parentResources = Resource::query()
                    ->whereNotNull('doi')
                    ->where(function ($query) use ($handles): void {
                        foreach ($handles as $handle) {
                            $query->orWhere(DB::raw('UPPER(doi)'), 'LIKE', '%/' . $handle);
                        }
                    })
                    ->get()
                    ->keyBy(fn (Resource $r): string => strtoupper((string) substr((string) $r->doi, (int) strrpos((string) $r->doi, '/') + 1)));

                // Assign parents from the bulk result
                foreach ($handleMap as $handle => $metaRecords) {
                    $parentResource = $parentResources->get($handle);
                    if ($parentResource === null) {
                        continue;
                    }

                    foreach ($metaRecords as $igsnMeta) {
                        $igsnMeta->parent_resource_id = $parentResource->id;

                        $descJson = $igsnMeta->description_json;
                        unset($descJson['parent_igsn_handle']);
                        $igsnMeta->description_json = $descJson !== [] ? $descJson : null;

                        $igsnMeta->save();
                        $resolved++;
                    }
                }
            });

        Log::info('Parent-child resolution completed', ['resolved' => $resolved]);
    }

    /**
     * @param  array<int, string>  $skippedDois
     * @param  array<int, array{doi: string, error: string}>  $failedDois
     */
    private function updateProgressCounts(
        int $processed,
        int $imported,
        int $skipped,
        int $failed,
        int $enriched,
        array $skippedDois,
        array $failedDois,
        int $total
    ): void {
        if ($processed === 1 || $processed % 50 === 0 || $processed === $total) {
            $this->updateProgressKeys([
                'processed' => $processed,
                'imported' => $imported,
                'skipped' => $skipped,
                'failed' => $failed,
                'enriched' => $enriched,
                'skipped_dois' => $skippedDois,
                'failed_dois' => $failedDois,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateProgress(array $data): void
    {
        Cache::put($this->getCacheKey(), $data, now()->addHours(24));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function updateProgressKeys(array $data): void
    {
        $currentProgress = Cache::get($this->getCacheKey(), []);

        foreach ($data as $key => $value) {
            $currentProgress[$key] = $value;
        }

        Cache::put($this->getCacheKey(), $currentProgress, now()->addHours(24));
    }

    private function getCacheKey(): string
    {
        return "igsn_import:{$this->importId}";
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('IGSN import job failed completely', [
            'import_id' => $this->importId,
            'error' => $exception?->getMessage(),
        ]);

        $this->updateProgress([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    public function getImportId(): string
    {
        return $this->importId;
    }
}
