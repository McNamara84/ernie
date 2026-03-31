<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RorDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Background job for discovering missing ROR identifiers via the ROR API v2.
 *
 * Searches for ROR matches for affiliations, institutions, and funders
 * that lack a ROR-ID, scoring candidates by name similarity.
 */
class DiscoverRorsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 3600;

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
    }

    /**
     * Get the cache key for tracking job progress.
     */
    public static function getCacheKey(string $jobId): string
    {
        return "ror_discovery:{$jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(RorDiscoveryService $service): void
    {
        $cacheKey = self::getCacheKey($this->jobId);
        $startedAt = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Starting ROR discovery...',
            'totalEntities' => 0,
            'processedEntities' => 0,
            'newRorsFound' => 0,
            'startedAt' => $startedAt,
        ], now()->addHours(2));

        try {
            $lastTotal = 0;

            $newCount = $service->discoverAll(function (int $processed, int $total) use ($cacheKey, $startedAt, &$lastTotal) {
                $lastTotal = $total;
                Cache::put($cacheKey, [
                    'status' => 'running',
                    'progress' => "Checking entity {$processed} of {$total}...",
                    'totalEntities' => $total,
                    'processedEntities' => $processed,
                    'newRorsFound' => 0,
                    'startedAt' => $startedAt,
                ], now()->addHours(2));
            });

            Cache::put($cacheKey, [
                'status' => 'completed',
                'progress' => 'ROR discovery completed.',
                'totalEntities' => $lastTotal,
                'processedEntities' => $lastTotal,
                'newRorsFound' => $newCount,
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            Log::info('DiscoverRorsJob completed', [
                'jobId' => $this->jobId,
                'newRorsFound' => $newCount,
            ]);
        } catch (\Exception $e) {
            Log::error('DiscoverRorsJob failed', [
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
            'progress' => 'ROR discovery failed.',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        $this->releaseLock();

        Log::error('DiscoverRorsJob failed callback', [
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
            Cache::restoreLock('ror_discovery_running', $this->lockOwner)->release();
        }
    }
}
