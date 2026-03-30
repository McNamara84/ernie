<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\OrcidDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Background job for discovering missing ORCID identifiers via the ORCID Public API.
 *
 * Searches for ORCID matches for persons (creators/contributors) who lack an ORCID,
 * scoring candidates by affiliation similarity and respecting API rate limits.
 */
class DiscoverOrcidsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     * Configurable via ORCID_DISCOVERY_TIMEOUT env variable (default: 3600).
     */
    public int $timeout;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     *
     * @param  string  $jobId  Unique identifier for progress tracking (UUID format)
     * @param  string|null  $lockOwner  Cache lock owner token for releasing the lock on completion
     *
     * @throws \InvalidArgumentException If jobId is not a valid UUID
     */
    public function __construct(
        private readonly string $jobId,
        private readonly ?string $lockOwner = null,
    ) {
        if (! Str::isUuid($jobId)) {
            throw new \InvalidArgumentException(
                'Job ID must be a valid UUID'
            );
        }

        $this->timeout = (int) config('services.orcid.discovery_timeout', 3600);
    }

    /**
     * Get the cache key for tracking job progress.
     */
    public static function getCacheKey(string $jobId): string
    {
        return "orcid_discovery:{$jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(OrcidDiscoveryService $service): void
    {
        $cacheKey = self::getCacheKey($this->jobId);
        $startedAt = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Starting ORCID discovery...',
            'totalPersons' => 0,
            'processedPersons' => 0,
            'newOrcidsFound' => 0,
            'startedAt' => $startedAt,
        ], now()->addHours(2));

        try {
            $lastTotal = 0;

            $newCount = $service->discoverAll(function (int $processed, int $total) use ($cacheKey, $startedAt, &$lastTotal) {
                $lastTotal = $total;
                Cache::put($cacheKey, [
                    'status' => 'running',
                    'progress' => "Checking person {$processed} of {$total}...",
                    'totalPersons' => $total,
                    'processedPersons' => $processed,
                    'newOrcidsFound' => 0,
                    'startedAt' => $startedAt,
                ], now()->addHours(2));
            });

            Cache::put($cacheKey, [
                'status' => 'completed',
                'progress' => 'ORCID discovery completed.',
                'totalPersons' => $lastTotal,
                'processedPersons' => $lastTotal,
                'newOrcidsFound' => $newCount,
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            Log::info('DiscoverOrcidsJob completed', [
                'jobId' => $this->jobId,
                'newOrcidsFound' => $newCount,
            ]);
        } catch (\Exception $e) {
            Log::error('DiscoverOrcidsJob failed', [
                'jobId' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        $cacheKey = self::getCacheKey($this->jobId);
        $existing = Cache::get($cacheKey, []);

        Cache::put($cacheKey, [
            ...(is_array($existing) ? $existing : []),
            'status' => 'failed',
            'progress' => 'ORCID discovery failed.',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        $this->releaseLock();

        Log::error('DiscoverOrcidsJob failed callback', [
            'jobId' => $this->jobId,
            'error' => $exception?->getMessage(),
        ]);
    }

    /**
     * Release the cache lock if this job owns it.
     */
    private function releaseLock(): void
    {
        if ($this->lockOwner !== null) {
            Cache::restoreLock('orcid_discovery_running', $this->lockOwner)->release();
        }
    }
}
