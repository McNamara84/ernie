<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use App\Enums\PublicTrafficSurface;
use App\Models\PublicTrafficHourlyStatistic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PublicTrafficAggregateStore
{
    public function increment(
        CarbonImmutable $bucketStartedAt,
        PublicTrafficSurface $surface,
        bool $incrementSurface,
        bool $incrementCombined,
    ): void {
        if (! $incrementSurface && ! $incrementCombined) {
            return;
        }

        $timestamp = CarbonImmutable::now('UTC');
        $this->ensureBucketExists($bucketStartedAt, $timestamp);
        $updates = ['updated_at' => $timestamp];

        if ($incrementSurface) {
            $column = $surface->counterColumn();
            $updates[$column] = DB::raw(match ($surface) {
                PublicTrafficSurface::LANDING_PAGE => 'landing_page_unique_visitor_count + 1',
                PublicTrafficSurface::PORTAL => 'portal_unique_visitor_count + 1',
            });
        }

        if ($incrementCombined) {
            $updates['combined_unique_visitor_count'] = DB::raw('combined_unique_visitor_count + 1');
        }

        PublicTrafficHourlyStatistic::query()
            ->where('bucket_started_at', $bucketStartedAt)
            ->update($updates);
    }

    public function observeMinute(CarbonImmutable $observedAt): PublicTrafficHourlyStatistic
    {
        $minute = $observedAt->utc()->startOfMinute();
        $bucketStartedAt = $minute->startOfHour();

        return DB::transaction(function () use ($bucketStartedAt, $minute): PublicTrafficHourlyStatistic {
            $this->ensureBucketExists($bucketStartedAt, $minute);
            $statistic = PublicTrafficHourlyStatistic::query()
                ->where('bucket_started_at', $bucketStartedAt)
                ->lockForUpdate()
                ->first();

            if (! $statistic instanceof PublicTrafficHourlyStatistic) {
                throw new RuntimeException('The public traffic hourly bucket could not be created.');
            }

            $lastObserved = $statistic->last_observed_minute_at;
            if ($lastObserved !== null && $lastObserved->greaterThanOrEqualTo($minute)) {
                return $statistic;
            }

            $statistic->observed_minute_count = min(60, $statistic->observed_minute_count + 1);
            $statistic->last_observed_minute_at = $minute->toMutable();
            $statistic->save();

            return $statistic;
        });
    }

    private function ensureBucketExists(CarbonImmutable $bucketStartedAt, CarbonImmutable $timestamp): void
    {
        PublicTrafficHourlyStatistic::query()->insertOrIgnore([
            'bucket_started_at' => $bucketStartedAt,
            'landing_page_unique_visitor_count' => 0,
            'portal_unique_visitor_count' => 0,
            'combined_unique_visitor_count' => 0,
            'observed_minute_count' => 0,
            'last_observed_minute_at' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}
