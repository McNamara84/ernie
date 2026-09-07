<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SystemMetricSample;
use Illuminate\Console\Command;

final class PruneSystemMetrics extends Command
{
    protected $signature = 'system-metrics:prune';

    protected $description = 'Delete expired host VM system metric samples';

    public function handle(): int
    {
        $retentionDays = max(1, (int) config('system_metrics.retention_days', 30));
        $deleted = SystemMetricSample::query()
            ->where('recorded_at', '<', now('UTC')->subDays($retentionDays))
            ->delete();

        $this->components->info("Pruned {$deleted} expired system metric sample(s).");

        return self::SUCCESS;
    }
}
