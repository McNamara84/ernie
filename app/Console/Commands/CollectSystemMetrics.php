<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CacheKey;
use App\Services\SystemMetricsCollectorService;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CollectSystemMetrics extends Command
{
    use ChecksCacheTagging;

    protected $signature = 'system-metrics:collect';

    protected $description = 'Collect one CPU and memory sample from the host VM';

    public function handle(SystemMetricsCollectorService $collector): int
    {
        if (! config('system_metrics.enabled')) {
            $this->components->info('System metrics collection is disabled.');

            return self::SUCCESS;
        }

        try {
            $sample = $collector->collect();
        } catch (Throwable $exception) {
            $warningCacheKey = CacheKey::SYSTEM_METRICS_COLLECTION_WARNING;

            if ($this->getCacheInstance($warningCacheKey->tags())->add(
                $warningCacheKey->key(),
                true,
                $warningCacheKey->ttl(),
            )) {
                Log::warning('Failed to collect host VM system metrics.', [
                    'exception' => $exception->getMessage(),
                ]);
            }

            $this->components->error('Failed to collect host VM system metrics: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Collected host VM system metrics for {$sample->recorded_at->toIso8601String()}.");

        return self::SUCCESS;
    }
}
