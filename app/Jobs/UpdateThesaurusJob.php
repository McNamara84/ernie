<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ThesaurusSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Background job for updating a GCMD thesaurus from NASA KMS API.
 *
 * This job wraps the existing artisan commands (get-gcmd-science-keywords,
 * get-gcmd-platforms, get-gcmd-instruments) and provides progress tracking
 * via cache for the frontend to poll.
 */
class UpdateThesaurusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     *
     * Thesaurus updates typically take 30-90 seconds depending on the
     * vocabulary size and network conditions.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $thesaurusType  The thesaurus type (science_keywords, platforms, instruments)
     * @param  string  $jobId  Unique identifier for progress tracking (UUID format)
     *
     * @throws \InvalidArgumentException If thesaurusType is invalid or jobId is not a valid UUID
     */
    public function __construct(
        private string $thesaurusType,
        private string $jobId
    ) {
        // Validate thesaurus type
        if (! in_array($thesaurusType, ThesaurusSetting::getValidTypes(), true)) {
            throw new \InvalidArgumentException(
                "Invalid thesaurus type: {$thesaurusType}. Valid types: ".implode(', ', ThesaurusSetting::getValidTypes())
            );
        }

        // Validate UUID format
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
            throw new \InvalidArgumentException(
                "Invalid jobId format. Expected UUID, got: {$jobId}"
            );
        }

        // Normalize to lowercase for consistency
        $this->jobId = strtolower($jobId);
    }

    /**
     * Get the cache key for this job's status.
     */
    public static function getCacheKey(string $jobId): string
    {
        return "thesaurus_update:{$jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cacheKey = self::getCacheKey($this->jobId);

        Log::info('Starting thesaurus update job', [
            'job_id' => $this->jobId,
            'thesaurus_type' => $this->thesaurusType,
        ]);

        // Update status to running
        Cache::put($cacheKey, [
            'status' => 'running',
            'thesaurusType' => $this->thesaurusType,
            'progress' => 'Fetching data from NASA KMS API...',
            'startedAt' => now()->toIso8601String(),
        ], now()->addHours(1));

        try {
            $command = $this->getArtisanCommand();

            Log::info('Executing artisan command', [
                'job_id' => $this->jobId,
                'command' => $command,
            ]);

            $exitCode = Artisan::call($command);

            if ($exitCode === 0) {
                Log::info('Thesaurus update completed successfully', [
                    'job_id' => $this->jobId,
                    'thesaurus_type' => $this->thesaurusType,
                ]);

                Cache::put($cacheKey, [
                    'status' => 'completed',
                    'thesaurusType' => $this->thesaurusType,
                    'progress' => 'Update completed successfully',
                    'completedAt' => now()->toIso8601String(),
                ], now()->addHours(1));
            } else {
                $output = Artisan::output();

                Log::error('Thesaurus update command failed', [
                    'job_id' => $this->jobId,
                    'thesaurus_type' => $this->thesaurusType,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ]);

                Cache::put($cacheKey, [
                    'status' => 'failed',
                    'thesaurusType' => $this->thesaurusType,
                    'progress' => 'Update failed',
                    'error' => "Command exited with code {$exitCode}",
                    'failedAt' => now()->toIso8601String(),
                ], now()->addHours(1));
            }
        } catch (\Exception $e) {
            Log::error('Thesaurus update job failed with exception', [
                'job_id' => $this->jobId,
                'thesaurus_type' => $this->thesaurusType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            Cache::put($cacheKey, [
                'status' => 'failed',
                'thesaurusType' => $this->thesaurusType,
                'progress' => 'Update failed',
                'error' => $e->getMessage(),
                'failedAt' => now()->toIso8601String(),
            ], now()->addHours(1));

            throw $e;
        }
    }

    /**
     * Get the artisan command for this thesaurus type.
     */
    private function getArtisanCommand(): string
    {
        return match ($this->thesaurusType) {
            ThesaurusSetting::TYPE_SCIENCE_KEYWORDS => 'get-gcmd-science-keywords',
            ThesaurusSetting::TYPE_PLATFORMS => 'get-gcmd-platforms',
            ThesaurusSetting::TYPE_INSTRUMENTS => 'get-gcmd-instruments',
            default => throw new \InvalidArgumentException("Unknown thesaurus type: {$this->thesaurusType}"),
        };
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $cacheKey = self::getCacheKey($this->jobId);

        Log::error('Thesaurus update job failed', [
            'job_id' => $this->jobId,
            'thesaurus_type' => $this->thesaurusType,
            'error' => $exception?->getMessage(),
        ]);

        Cache::put($cacheKey, [
            'status' => 'failed',
            'thesaurusType' => $this->thesaurusType,
            'progress' => 'Update failed',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'failedAt' => now()->toIso8601String(),
        ], now()->addHours(1));
    }
}
