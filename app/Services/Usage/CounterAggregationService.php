<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CounterAggregationService
{
    /** @var list<string> */
    public const METRICS = ['total_investigations', 'unique_investigations', 'total_requests', 'unique_requests'];

    public function aggregate(?string $through = null): int
    {
        $state = app(CounterTrackingStateService::class)->read();
        if ($state->started_at === null) {
            return 0;
        }
        $end = $through === null ? CarbonImmutable::yesterday('UTC') : CarbonImmutable::parse($through, 'UTC')->startOfDay();
        if ($end->gte(CarbonImmutable::today('UTC'))) {
            throw new InvalidArgumentException('Only completed UTC days can be aggregated.');
        }
        $count = 0;
        $start = $state->data_through === null
            ? CarbonImmutable::parse($state->started_at, 'UTC')->startOfDay()
            : CarbonImmutable::parse($state->data_through, 'UTC')->addDay();
        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $count += $this->day($day) ? 1 : 0;
        }

        return $count;
    }

    private function day(CarbonImmutable $date): bool
    {
        return DB::transaction(function () use ($date): bool {
            $state = DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            $day = $date->toDateString();
            $existing = DB::table('usage_aggregation_days')->where('day', $day)->first();
            if ($existing?->finalized_at !== null) {
                return false;
            }
            DB::table('usage_daily_metrics')->where('day', $day)->delete();
            $events = $this->filtered('usage_events', $day, 'landing_page_id, access_method, session_hash, event_type, target_hash');
            $metrics = DB::query()->fromSub($events, 'events')
                ->join('landing_pages', 'landing_pages.id', '=', 'events.landing_page_id')
                ->join('resources', 'resources.id', '=', 'landing_pages.resource_id')
                ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id')
                ->selectRaw('events.landing_page_id, ? as day, events.access_method', [$day])
                ->selectRaw('COUNT(*) as total_investigations, COUNT(DISTINCT session_hash) as unique_investigations')
                ->selectRaw("CASE WHEN resource_types.slug = 'physical-object' THEN NULL ELSE SUM(CASE WHEN event_type = 'request' THEN 1 ELSE 0 END) END as total_requests")
                ->selectRaw("CASE WHEN resource_types.slug = 'physical-object' THEN NULL ELSE COUNT(DISTINCT CASE WHEN event_type = 'request' THEN session_hash ELSE NULL END) END as unique_requests")
                ->groupBy('events.landing_page_id', 'events.access_method', 'resource_types.slug');
            DB::table('usage_daily_metrics')->insertUsing(
                ['landing_page_id', 'day', 'access_method', ...self::METRICS], $metrics,
            );

            DB::table('usage_search_daily_metrics')->where('day', $day)->delete();
            $searches = $this->filtered('usage_search_events', $day, 'portal_scope, access_method, session_hash, term_hash');
            DB::table('usage_search_daily_metrics')->insertUsing(
                ['day', 'portal_scope', 'term_hash', 'normalized_term', 'access_method', 'search_count'],
                DB::query()->fromSub($searches, 'searches')
                    ->selectRaw('? as day, portal_scope, term_hash, MIN(normalized_term), access_method, COUNT(*)', [$day])
                    ->groupBy('portal_scope', 'term_hash', 'access_method'),
            );
            $this->rebuildLockedMonth($date->startOfMonth());
            DB::table('usage_aggregation_days')->updateOrInsert(['day' => $day], [
                'finalized_at' => CarbonImmutable::now('UTC'),
                'incomplete' => (bool) ($existing->incomplete ?? false)
                    || ($state->paused && $date->gte(CarbonImmutable::parse($state->paused_at, 'UTC')->startOfDay())),
            ]);
            DB::table('usage_events')->where('day', $day)->delete();
            DB::table('usage_search_events')->where('day', $day)->delete();
            DB::table('usage_tracking_state')->where('id', 1)->update([
                'data_through' => $day, 'last_aggregated_at' => CarbonImmutable::now('UTC'),
                'metrics_revision' => DB::raw('metrics_revision + 1'),
            ]);

            return true;
        }, 3);
    }

    /** @param literal-string $partition */
    private function filtered(string $table, string $day, string $partition): Builder
    {
        $window = DB::table($table)->where('day', $day)
            ->select('*')
            ->selectRaw("LEAD(occurred_at_ms) OVER (PARTITION BY {$partition} ORDER BY occurred_at_ms, id) AS next_at_ms");

        return DB::query()->fromSub($window, 'ordered_events')
            ->where(function (Builder $query): void {
                $query->whereNull('next_at_ms')->orWhereRaw('next_at_ms - occurred_at_ms > 30000');
            });
    }

    public function rebuildMonth(CarbonImmutable $month): void
    {
        DB::transaction(function () use ($month): void {
            DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            $this->rebuildLockedMonth($month);
            DB::table('usage_tracking_state')->where('id', 1)->increment('metrics_revision');
        });
    }

    private function rebuildLockedMonth(CarbonImmutable $month): void
    {
        $month = $month->startOfMonth();
        DB::table('usage_monthly_metrics')->where('month', $month->toDateString())->delete();
        $totals = DB::table('usage_daily_metrics')
            ->whereBetween('day', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('landing_page_id, ? as month, access_method', [$month->toDateString()])
            ->groupBy('landing_page_id', 'access_method');
        foreach (self::METRICS as $metric) {
            $totals->selectRaw("SUM({$metric}) as {$metric}");
        }
        DB::table('usage_monthly_metrics')->insertUsing(
            ['landing_page_id', 'month', 'access_method', ...self::METRICS], $totals,
        );
    }

    public function prune(): int
    {
        return DB::transaction(function (): int {
            DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            $cutoff = CarbonImmutable::now('UTC')->subDays(6)->subHours(23)->getTimestampMs();
            $count = 0;
            foreach (['usage_events', 'usage_search_events'] as $table) {
                $days = DB::table($table)->where('occurred_at_ms', '<=', $cutoff)->distinct()->pluck('day');
                foreach ($days as $day) {
                    DB::table('usage_aggregation_days')->upsert([['day' => $day, 'incomplete' => true]], ['day'], ['incomplete']);
                }
                $count += DB::table($table)->where('occurred_at_ms', '<=', $cutoff)->delete();
            }
            if ($count > 0) {
                DB::table('usage_tracking_state')->where('id', 1)->increment('metrics_revision');
            }

            return $count;
        });
    }
}
