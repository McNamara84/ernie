<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Models\LandingPage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;

final class CounterReadService
{
    /**
     * A shared lock gives all aggregate reads one published revision, including
     * on MySQL connections configured with READ COMMITTED.
     *
     * @template T
     *
     * @param  Closure(): T  $read
     * @return T
     */
    public function snapshot(Closure $read): mixed
    {
        return DB::transaction(function () use ($read) {
            DB::table('usage_tracking_state')->where('id', 1)->sharedLock()->firstOrFail();

            return $read();
        });
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $state = app(CounterTrackingStateService::class)->read();
        $gaps = DB::table('usage_aggregation_days')->where('incomplete', true)->orderBy('day')->pluck('day')->all();

        return [
            'state' => $state->started_at === null ? 'inactive' : ($state->data_through === null ? 'pending' : ($gaps !== [] || $state->paused ? 'incomplete' : 'ready')),
            'startedAt' => $state->started_at === null ? null : CarbonImmutable::parse($state->started_at, 'UTC')->toIso8601String(),
            'lastAggregatedAt' => $state->last_aggregated_at === null ? null : CarbonImmutable::parse($state->last_aggregated_at, 'UTC')->toIso8601String(),
            'dataThrough' => $state->data_through,
            'metricsRevision' => (int) $state->metrics_revision,
            'paused' => (bool) $state->paused,
            'incompleteDays' => $gaps,
            'methodologyUrl' => config('usage.methodology_url'),
        ];
    }

    /** @return array<string, mixed> */
    public function landingPage(?LandingPage $page, bool $igsn = false): array
    {
        return $this->snapshot(function () use ($page, $igsn): array {
            $status = $this->status();
            $igsn = $igsn || $page?->resource->resourceType?->slug === 'physical-object';
            $result = [...$status, 'views' => 0, 'downloads' => $igsn ? null : 0, 'months' => []];
            if ($page === null || ! $page->isPublished()) {
                $result['state'] = 'placeholder';

                return $result;
            }
            if ($status['startedAt'] === null) {
                return $result;
            }
            $query = DB::table('usage_monthly_metrics')->where('landing_page_id', $page->id)->where('access_method', 'Regular');
            $result['views'] = (int) (clone $query)->sum('unique_investigations');
            $result['downloads'] = $igsn ? null : (int) (clone $query)->sum('unique_requests');
            $activation = CarbonImmutable::parse($status['startedAt'], 'UTC');
            $start = CarbonImmutable::now('UTC')->startOfMonth()->subMonths(11)->max($activation->startOfMonth());
            $end = CarbonImmutable::now('UTC')->startOfMonth();
            $rows = $query->where('month', '>=', $start->toDateString())->get()->keyBy('month');
            $through = $status['dataThrough'] === null ? null : CarbonImmutable::parse($status['dataThrough'], 'UTC');
            for ($month = $start; $month->lte($end); $month = $month->addMonth()) {
                $row = $rows->get($month->toDateString());
                $covered = $through !== null && $month->lte($through);
                $incomplete = array_any($status['incompleteDays'], fn (string $day): bool => str_starts_with($day, $month->format('Y-m')));
                $result['months'][] = [
                    'month' => $month->format('Y-m'),
                    'views' => $covered ? (int) ($row->unique_investigations ?? 0) : null,
                    'downloads' => $igsn || ! $covered ? null : (int) ($row->unique_requests ?? 0),
                    'partial' => $activation->gt($month) || $through === null || $through->lt($month->endOfMonth()->startOfDay()),
                    'incomplete' => $incomplete,
                ];
            }

            return $result;
        });
    }
}
