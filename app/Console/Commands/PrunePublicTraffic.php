<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PublicTrafficHourlyStatistic;
use Illuminate\Console\Command;

final class PrunePublicTraffic extends Command
{
    protected $signature = 'public-traffic:prune';

    protected $description = 'Delete expired anonymous public traffic aggregates';

    public function handle(): int
    {
        $retentionDays = max(365, (int) config('public_traffic.retention_days', 400));
        $deleted = PublicTrafficHourlyStatistic::query()
            ->where('bucket_started_at', '<', now('UTC')->subDays($retentionDays)->startOfHour())
            ->delete();

        $this->components->info("Pruned {$deleted} expired public traffic aggregate(s).");

        return self::SUCCESS;
    }
}
