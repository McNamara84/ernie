<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Usage\CounterTrackingStateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CounterTracking extends Command
{
    protected $signature = 'counter:tracking {action=status : status, activate, pause, or resume}';

    protected $description = 'Inspect COUNTER tracking or change its explicit, environment-specific activation state';

    public function handle(CounterTrackingStateService $state): int
    {
        switch ($this->argument('action')) {
            case 'activate':
                $state->activate();
                break;
            case 'pause':
                $state->pause(true);
                break;
            case 'resume':
                $state->pause(false);
                break;
            case 'status':
                break;
            default:
                $this->error('Use status, activate, pause, or resume.');

                return self::INVALID;
        }
        $current = $state->read();
        $oldest = collect(['usage_events', 'usage_search_events'])
            ->map(fn (string $table) => DB::table($table)->min('occurred_at_ms'))->filter()->min();
        $this->table(['Status', 'Started (UTC)', 'Data through (UTC)', 'Last aggregation (UTC)', 'List version', 'Incomplete days', 'Oldest raw event (UTC)'], [[
            $current->started_at === null ? 'Inactive' : ($current->paused ? 'Paused' : 'Active'),
            $current->started_at ?? '—', $current->data_through ?? '—',
            $current->last_aggregated_at ?? '—', $current->list_version_id ?? '—',
            DB::table('usage_aggregation_days')->where('incomplete', true)->count(),
            $oldest === null ? '—' : CarbonImmutable::createFromTimestampMs((int) $oldest, 'UTC')->toDateTimeString(),
        ]]);

        return self::SUCCESS;
    }
}
