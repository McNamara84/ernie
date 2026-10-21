<?php

declare(strict_types=1);

namespace App\Services\Usage;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

final class CounterTrackingStateService
{
    public function markGap(CarbonImmutable $from, CarbonImmutable $through): void
    {
        DB::transaction(function () use ($from, $through): void {
            $state = DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($state->started_at === null || $from->gt($through) || $through->gt(CarbonImmutable::today('UTC'))) {
                throw new InvalidArgumentException('Gap dates must be ordered, not in the future, and tracking must have started.');
            }
            $start = $from->utc()->startOfDay()->max(CarbonImmutable::parse($state->started_at, 'UTC')->startOfDay());
            for ($day = $start; $day->lte($through); $day = $day->addDay()) {
                DB::table('usage_aggregation_days')->upsert(
                    [['day' => $day->toDateString(), 'incomplete' => true]], ['day'], ['incomplete'],
                );
            }
            DB::table('usage_tracking_state')->where('id', 1)->increment('metrics_revision');
        });
    }

    public function read(): stdClass
    {
        return DB::table('usage_tracking_state')->where('id', 1)->firstOrFail();
    }

    /**
     * The shared state lock makes activation wait for every legacy writer.
     * All nested counter writes participate in this same transaction.
     */
    public function legacy(Closure $write): void
    {
        DB::transaction(function () use ($write): void {
            $state = DB::table('usage_tracking_state')->where('id', 1)->sharedLock()->firstOrFail();
            if ($state->started_at === null) {
                $write();
            }
        });
    }

    public function activate(): CarbonImmutable
    {
        return DB::transaction(function (): CarbonImmutable {
            $state = DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            if (strlen((string) config('usage.session_secret')) < 32) {
                throw new RuntimeException('COUNTER_SESSION_SECRET must contain at least 32 characters.');
            }
            if ($state->started_at !== null) {
                return CarbonImmutable::parse($state->started_at, 'UTC');
            }
            if ($state->list_version_id === null) {
                app(CounterListService::class)->installBundled();
            }
            $now = CarbonImmutable::now('UTC');
            DB::table('usage_tracking_state')->where('id', 1)->update(['started_at' => $now, 'paused' => false]);

            return $now;
        });
    }

    public function pause(bool $paused): void
    {
        DB::transaction(function () use ($paused): void {
            $state = DB::table('usage_tracking_state')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($state->started_at === null) {
                throw new RuntimeException('Activate COUNTER tracking first.');
            }
            if (! $paused && strlen((string) config('usage.session_secret')) < 32) {
                throw new RuntimeException('COUNTER_SESSION_SECRET must contain at least 32 characters.');
            }
            if ((bool) $state->paused === $paused) {
                return;
            }
            // A pause is a known observation gap, including each day crossed while paused.
            $start = $paused ? CarbonImmutable::today('UTC') : CarbonImmutable::parse($state->paused_at, 'UTC')->startOfDay();
            for ($day = $start; $day->lte(CarbonImmutable::today('UTC')); $day = $day->addDay()) {
                DB::table('usage_aggregation_days')->upsert(
                    [['day' => $day->toDateString(), 'incomplete' => true]],
                    ['day'],
                    ['incomplete'],
                );
            }
            DB::table('usage_tracking_state')->where('id', 1)->update([
                'paused' => $paused,
                'paused_at' => $paused ? CarbonImmutable::now('UTC') : null,
                'metrics_revision' => DB::raw('metrics_revision + 1'),
            ]);
        });
    }
}
