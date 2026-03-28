<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RelationDiscoveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Background job for discovering new related works via external APIs.
 *
 * Queries ScholExplorer and DataCite Event Data APIs for all registered DOIs
 * and stores new suggestions for curator review.
 */
class DiscoverRelationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The maximum number of seconds the job can run.
     * Many DOIs × API calls can take significant time.
     */
    public int $timeout = 3600; // 1 hour

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
                "Job ID must be a valid UUID, got: {$jobId}"
            );
        }
    }

    /**
     * Get the cache key for tracking job progress.
     */
    public static function getCacheKey(string $jobId): string
    {
        return "relation_discovery:{$jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(RelationDiscoveryService $service): void
    {
        $cacheKey = self::getCacheKey($this->jobId);
        $startedAt = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Starting relation discovery...',
            'totalDois' => 0,
            'processedDois' => 0,
            'newRelationsFound' => 0,
            'startedAt' => $startedAt,
        ], now()->addHours(2));

        try {
            $lastTotal = 0;

            $newCount = $service->discoverAll(function (int $processed, int $total) use ($cacheKey, $startedAt, &$lastTotal) {
                $lastTotal = $total;
                Cache::put($cacheKey, [
                    'status' => 'running',
                    'progress' => "Checking DOI {$processed} of {$total}...",
                    'totalDois' => $total,
                    'processedDois' => $processed,
                    'newRelationsFound' => 0,
                    'startedAt' => $startedAt,
                ], now()->addHours(2));
            });

            Cache::put($cacheKey, [
                'status' => 'completed',
                'progress' => 'Discovery completed.',
                'totalDois' => $lastTotal,
                'processedDois' => $lastTotal,
                'newRelationsFound' => $newCount,
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            Log::info('DiscoverRelationsJob completed', [
                'jobId' => $this->jobId,
                'newRelationsFound' => $newCount,
            ]);
        } catch (\Exception $e) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'progress' => 'Discovery failed.',
                'error' => $e->getMessage(),
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            Log::error('DiscoverRelationsJob failed', [
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

        Cache::put($cacheKey, [
            'status' => 'failed',
            'progress' => 'Discovery failed.',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        $this->releaseLock();

        Log::error('DiscoverRelationsJob failed callback', [
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
            Cache::restoreLock('relation_discovery_running', $this->lockOwner)->release();
        }
    }
}
