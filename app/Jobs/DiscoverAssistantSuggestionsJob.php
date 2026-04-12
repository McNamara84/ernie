<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CacheKey;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Assistance\GenericTableAssistant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Generic background job for discovering suggestions via student-created assistant modules.
 *
 * This job is dispatched by GenericTableAssistant::dispatchDiscovery() and
 * delegates the actual discovery work to the module's discover() method.
 *
 * Existing assistants (ORCID, ROR, Relations) use their own dedicated job classes.
 * Only new assistant modules that extend GenericTableAssistant use this job.
 */
class DiscoverAssistantSuggestionsJob implements ShouldQueue
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
     * @param  string  $assistantId  The assistant module ID (e.g. "spdx-license")
     * @param  string  $jobId  Unique identifier for progress tracking (UUID format)
     * @param  string|null  $lockOwner  Cache lock owner token for releasing the lock on completion
     *
     * @throws \InvalidArgumentException If jobId is not a valid UUID
     */
    public function __construct(
        private readonly string $assistantId,
        private readonly string $jobId,
        private readonly ?string $lockOwner = null,
    ) {
        if (! Str::isUuid($jobId)) {
            throw new \InvalidArgumentException('Job ID must be a valid UUID');
        }
    }

    /**
     * Get the cache key for tracking job progress.
     *
     * Resolves the assistant from the registrar to use the manifest's cache key prefix,
     * matching the key the controller polls via AssistantContract::getJobStatusCacheKey().
     */
    public function getCacheKey(): string
    {
        $assistant = app(AssistantRegistrar::class)->get($this->assistantId);

        if ($assistant !== null) {
            return $assistant->getJobStatusCacheKey($this->jobId);
        }

        // Fallback for edge cases (assistant removed between dispatch and execution)
        return "{$this->assistantId}:{$this->jobId}";
    }

    /**
     * Execute the job.
     */
    public function handle(AssistantRegistrar $registrar): void
    {
        $cacheKey = $this->getCacheKey();
        $startedAt = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Starting discovery...',
            'startedAt' => $startedAt,
        ], now()->addHours(2));

        $assistant = $registrar->get($this->assistantId);

        if (! $assistant instanceof GenericTableAssistant) {
            Cache::put($cacheKey, [
                'status' => 'failed',
                'progress' => 'Assistant module not found or not a generic assistant.',
                'error' => "Assistant '{$this->assistantId}' is not registered or is not a GenericTableAssistant.",
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            $this->releaseLock();

            return;
        }

        try {
            $newCount = $assistant->runDiscovery(function (string $progressMessage) use ($cacheKey, $startedAt) {
                Cache::put($cacheKey, [
                    'status' => 'running',
                    'progress' => $progressMessage,
                    'startedAt' => $startedAt,
                ], now()->addHours(2));
            });

            Cache::put($cacheKey, [
                'status' => 'completed',
                'progress' => 'Discovery completed.',
                'newSuggestionsFound' => $newCount,
                'startedAt' => $startedAt,
                'completedAt' => now()->toIso8601String(),
            ], now()->addHours(2));

            Log::info('DiscoverAssistantSuggestionsJob completed', [
                'assistantId' => $this->assistantId,
                'jobId' => $this->jobId,
                'newSuggestionsFound' => $newCount,
            ]);

            if ($newCount > 0) {
                Cache::forget(CacheKey::ASSISTANCE_TOTAL_PENDING_COUNT->key());
            }
        } catch (\Exception $e) {
            Log::error('DiscoverAssistantSuggestionsJob failed', [
                'assistantId' => $this->assistantId,
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
        $cacheKey = $this->getCacheKey();
        $existing = Cache::get($cacheKey, []);

        Cache::put($cacheKey, [
            ...(is_array($existing) ? $existing : []),
            'status' => 'failed',
            'progress' => 'Discovery failed.',
            'error' => $exception?->getMessage() ?? 'Unknown error',
            'completedAt' => now()->toIso8601String(),
        ], now()->addHours(2));

        $this->releaseLock();

        Log::error('DiscoverAssistantSuggestionsJob failed callback', [
            'assistantId' => $this->assistantId,
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
            $assistant = app(AssistantRegistrar::class)->get($this->assistantId);
            $lockKey = $assistant?->getLockKey() ?? "{$this->assistantId}_discovery_running";
            Cache::restoreLock($lockKey, $this->lockOwner)->release();
        }
    }
}
