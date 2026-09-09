<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use App\Enums\PublicTrafficPeriod;
use App\Models\PublicTrafficHourlyStatistic;
use Carbon\CarbonImmutable;

final class PublicTrafficHistoryService
{
    private const WEEKDAY_COUNT = 7;

    private const HOURS_PER_DAY = 24;

    /** @return array<string, mixed> */
    public function history(PublicTrafficPeriod $period): array
    {
        $timezone = (string) config('public_traffic.display_timezone', 'Europe/Berlin');
        $endsAt = CarbonImmutable::now('UTC')->startOfHour();
        $requestedStartsAt = $period->startsAt($endsAt);
        $collectionStartedAt = $this->collectionStartedAt();

        if (! config('public_traffic.enabled')) {
            return $this->emptyHistory($period, $timezone, $requestedStartsAt, $endsAt, $collectionStartedAt, 'disabled');
        }

        if ($collectionStartedAt === null || ! $collectionStartedAt->lessThan($endsAt)) {
            return $this->emptyHistory($period, $timezone, $requestedStartsAt, $endsAt, $collectionStartedAt, 'collecting');
        }

        $effectiveStartsAt = $collectionStartedAt->greaterThan($requestedStartsAt)
            ? $collectionStartedAt
            : $requestedStartsAt;
        $rows = PublicTrafficHourlyStatistic::query()
            ->where('bucket_started_at', '>=', $effectiveStartsAt)
            ->where('bucket_started_at', '<', $endsAt)
            ->oldest('bucket_started_at')
            ->get()
            ->keyBy(fn (PublicTrafficHourlyStatistic $row): string => $row->bucket_started_at->utc()->format('Y-m-d H:i:s'));
        $cells = $this->emptyCells();
        $completeHours = 0;
        $excludedHours = 0;
        $lastCompleteAt = null;

        for ($bucket = $effectiveStartsAt; $bucket->lessThan($endsAt); $bucket = $bucket->addHour()) {
            /** @var PublicTrafficHourlyStatistic|null $row */
            $row = $rows->get($bucket->format('Y-m-d H:i:s'));

            if (! $row instanceof PublicTrafficHourlyStatistic || $row->observed_minute_count !== 60) {
                $excludedHours++;

                continue;
            }

            $local = $bucket->setTimezone($timezone);
            $dayIndex = $local->isoWeekday() - 1;
            $hour = (int) $local->format('G');
            $cell = $cells[$dayIndex][$hour];
            $cell['combinedTotal'] += $row->combined_unique_visitor_count;
            $cell['landingPageTotal'] += $row->landing_page_unique_visitor_count;
            $cell['portalTotal'] += $row->portal_unique_visitor_count;
            $cell['sampleCount']++;
            $cells[$dayIndex][$hour] = $cell;
            $completeHours++;
            $lastCompleteAt = $bucket;
        }

        $presentedCells = $this->presentCells($cells);
        $minimumSampleCount = PHP_INT_MAX;
        foreach ($presentedCells as $cell) {
            $minimumSampleCount = min($minimumSampleCount, $cell['sampleCount']);
        }
        $status = $minimumSampleCount > 0 ? 'available' : 'collecting';
        [$quietest, $busiest] = $status === 'available'
            ? $this->rank($presentedCells)
            : [[], []];

        return [
            'period' => $period->value,
            'periodWeeks' => $period->weeks(),
            'timezone' => $timezone,
            'requestedFrom' => $requestedStartsAt->toIso8601String(),
            'effectiveFrom' => $effectiveStartsAt->toIso8601String(),
            'to' => $endsAt->toIso8601String(),
            'status' => $status,
            'collectionStartedAt' => $collectionStartedAt->toIso8601String(),
            'lastCompleteBucketAt' => $lastCompleteAt?->toIso8601String(),
            'coverage' => [
                'completeHours' => $completeHours,
                'excludedHours' => $excludedHours,
                'minimumCellSampleCount' => $minimumSampleCount,
                'lowSampleWarning' => $minimumSampleCount < 4,
            ],
            'cells' => $presentedCells,
            'quietest' => $quietest,
            'busiest' => $busiest,
        ];
    }

    private function collectionStartedAt(): ?CarbonImmutable
    {
        $value = PublicTrafficHourlyStatistic::query()
            ->where('observed_minute_count', '>', 0)
            ->oldest('bucket_started_at')
            ->value('bucket_started_at');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->startOfHour();
    }

    /** @return array<string, mixed> */
    private function emptyHistory(
        PublicTrafficPeriod $period,
        string $timezone,
        CarbonImmutable $requestedStartsAt,
        CarbonImmutable $endsAt,
        ?CarbonImmutable $collectionStartedAt,
        string $status,
    ): array {
        return [
            'period' => $period->value,
            'periodWeeks' => $period->weeks(),
            'timezone' => $timezone,
            'requestedFrom' => $requestedStartsAt->toIso8601String(),
            'effectiveFrom' => $collectionStartedAt?->toIso8601String(),
            'to' => $endsAt->toIso8601String(),
            'status' => $status,
            'collectionStartedAt' => $collectionStartedAt?->toIso8601String(),
            'lastCompleteBucketAt' => null,
            'coverage' => [
                'completeHours' => 0,
                'excludedHours' => 0,
                'minimumCellSampleCount' => 0,
                'lowSampleWarning' => true,
            ],
            'cells' => $this->presentCells($this->emptyCells()),
            'quietest' => [],
            'busiest' => [],
        ];
    }

    /** @return array<int, array<int, array{weekday:int, hour:int, combinedTotal:int, landingPageTotal:int, portalTotal:int, sampleCount:int}>> */
    private function emptyCells(): array
    {
        $cells = [];

        for ($weekday = 1; $weekday <= self::WEEKDAY_COUNT; $weekday++) {
            $day = [];
            for ($hour = 0; $hour < self::HOURS_PER_DAY; $hour++) {
                $day[] = [
                    'weekday' => $weekday,
                    'hour' => $hour,
                    'combinedTotal' => 0,
                    'landingPageTotal' => 0,
                    'portalTotal' => 0,
                    'sampleCount' => 0,
                ];
            }
            $cells[] = $day;
        }

        return $cells;
    }

    /**
     * @param  array<int, array<int, array{weekday:int, hour:int, combinedTotal:int, landingPageTotal:int, portalTotal:int, sampleCount:int}>>  $cells
     * @return list<array{weekday:int, hour:int, combinedAverage:float|null, landingPageAverage:float|null, portalAverage:float|null, sampleCount:int}>
     */
    private function presentCells(array $cells): array
    {
        $presented = [];

        foreach ($cells as $day) {
            foreach ($day as $cell) {
                $sampleCount = $cell['sampleCount'];
                $presented[] = [
                    'weekday' => $cell['weekday'],
                    'hour' => $cell['hour'],
                    'combinedAverage' => $sampleCount > 0 ? round($cell['combinedTotal'] / $sampleCount, 2) : null,
                    'landingPageAverage' => $sampleCount > 0 ? round($cell['landingPageTotal'] / $sampleCount, 2) : null,
                    'portalAverage' => $sampleCount > 0 ? round($cell['portalTotal'] / $sampleCount, 2) : null,
                    'sampleCount' => $sampleCount,
                ];
            }
        }

        return $presented;
    }

    /**
     * @param  list<array{weekday:int, hour:int, combinedAverage:float|null, landingPageAverage:float|null, portalAverage:float|null, sampleCount:int}>  $cells
     * @return array{0:list<array<string, int|float|null>>, 1:list<array<string, int|float|null>>}
     */
    private function rank(array $cells): array
    {
        $quietest = $cells;
        usort($quietest, fn (array $left, array $right): int => $this->compareCells($left, $right));
        $busiest = $cells;
        usort($busiest, function (array $left, array $right): int {
            $averageComparison = ($right['combinedAverage'] ?? 0.0) <=> ($left['combinedAverage'] ?? 0.0);

            return $averageComparison !== 0
                ? $averageComparison
                : [$left['weekday'], $left['hour']] <=> [$right['weekday'], $right['hour']];
        });

        return [array_slice($quietest, 0, 3), array_slice($busiest, 0, 3)];
    }

    /**
     * @param  array{weekday:int, hour:int, combinedAverage:float|null}  $left
     * @param  array{weekday:int, hour:int, combinedAverage:float|null}  $right
     */
    private function compareCells(array $left, array $right): int
    {
        $averageComparison = ($left['combinedAverage'] ?? 0.0) <=> ($right['combinedAverage'] ?? 0.0);

        return $averageComparison !== 0
            ? $averageComparison
            : [$left['weekday'], $left['hour']] <=> [$right['weekday'], $right['hour']];
    }
}
