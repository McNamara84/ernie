<?php

declare(strict_types=1);

use App\Enums\PublicTrafficPeriod;
use App\Models\PublicTrafficHourlyStatistic;
use App\Services\PublicTraffic\PublicTrafficHistoryService;
use Carbon\CarbonImmutable;

covers(PublicTrafficPeriod::class, PublicTrafficHistoryService::class);

beforeEach(function (): void {
    config()->set([
        'public_traffic.enabled' => true,
        'public_traffic.display_timezone' => 'Europe/Berlin',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function createPublicTrafficHour(
    CarbonImmutable|string $bucket,
    int $combined = 0,
    int $landingPages = 0,
    int $portal = 0,
    int $observedMinutes = 60,
): PublicTrafficHourlyStatistic {
    $startedAt = $bucket instanceof CarbonImmutable ? $bucket : CarbonImmutable::parse($bucket, 'UTC');

    return PublicTrafficHourlyStatistic::query()->create([
        'bucket_started_at' => $startedAt,
        'landing_page_unique_visitor_count' => $landingPages,
        'portal_unique_visitor_count' => $portal,
        'combined_unique_visitor_count' => $combined,
        'observed_minute_count' => $observedMinutes,
        'last_observed_minute_at' => $observedMinutes > 0 ? $startedAt->addMinutes($observedMinutes - 1) : null,
    ]);
}

it('builds 168 Berlin weekday-hour cells and ranks complete weekly coverage', function (): void {
    CarbonImmutable::setTestNow('2026-09-08 00:00:00 UTC');
    $start = CarbonImmutable::now('UTC')->subWeek();

    for ($offset = 0; $offset < 168; $offset++) {
        $bucket = $start->addHours($offset);
        $local = $bucket->setTimezone('Europe/Berlin');
        $value = ($local->isoWeekday() - 1) * 24 + (int) $local->format('G');
        createPublicTrafficHour($bucket, combined: $value, landingPages: intdiv($value, 2), portal: $value);
    }

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::TWELVE_WEEKS);

    expect($history['period'])->toBe('12w')
        ->and($history['periodWeeks'])->toBe(12)
        ->and($history['timezone'])->toBe('Europe/Berlin')
        ->and($history['status'])->toBe('available')
        ->and($history['cells'])->toHaveCount(168)
        ->and($history['coverage']['completeHours'])->toBe(168)
        ->and($history['coverage']['excludedHours'])->toBe(0)
        ->and($history['coverage']['minimumCellSampleCount'])->toBe(1)
        ->and($history['coverage']['lowSampleWarning'])->toBeTrue()
        ->and($history['quietest'])->toHaveCount(3)
        ->and($history['quietest'][0]['weekday'])->toBe(1)
        ->and($history['quietest'][0]['hour'])->toBe(0)
        ->and(array_column($history['quietest'], 'hour'))->toBe([0, 1, 2])
        ->and($history['busiest'][0]['combinedAverage'])->toBe(167.0)
        ->and(array_column($history['busiest'], 'combinedAverage'))->toBe([167.0, 166.0, 165.0]);
});

it('uses real zeroes but excludes missing and partially observed hours', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 15:00:00 UTC');
    createPublicTrafficHour('2026-09-09 12:00:00', combined: 0);
    createPublicTrafficHour('2026-09-09 13:00:00', combined: 99, observedMinutes: 59);

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::FOUR_WEEKS);
    $validCell = collect($history['cells'])->first(
        fn (array $cell): bool => $cell['weekday'] === 3 && $cell['hour'] === 14,
    );

    expect($history['status'])->toBe('collecting')
        ->and($history['coverage']['completeHours'])->toBe(1)
        ->and($history['coverage']['excludedHours'])->toBe(2)
        ->and($validCell['combinedAverage'])->toBe(0.0)
        ->and($validCell['sampleCount'])->toBe(1)
        ->and($history['quietest'])->toBe([])
        ->and($history['busiest'])->toBe([]);
});

it('returns disabled and empty collection states honestly', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 15:00:00 UTC');
    $service = app(PublicTrafficHistoryService::class);

    config()->set('public_traffic.enabled', false);
    $disabled = $service->history(PublicTrafficPeriod::FOUR_WEEKS);
    config()->set('public_traffic.enabled', true);
    $collecting = $service->history(PublicTrafficPeriod::FIFTY_TWO_WEEKS);

    expect($disabled['status'])->toBe('disabled')
        ->and($disabled['cells'])->toHaveCount(168)
        ->and($collecting['status'])->toBe('collecting')
        ->and($collecting['periodWeeks'])->toBe(52)
        ->and($collecting['effectiveFrom'])->toBeNull();
});

it('maps the missing spring hour and repeated autumn hour using Europe Berlin DST rules', function (): void {
    CarbonImmutable::setTestNow('2026-10-26 03:00:00 UTC');
    createPublicTrafficHour('2026-03-29 00:00:00', combined: 1);
    createPublicTrafficHour('2026-03-29 01:00:00', combined: 3);
    createPublicTrafficHour('2026-10-25 00:00:00', combined: 2);
    createPublicTrafficHour('2026-10-25 01:00:00', combined: 4);

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::FIFTY_TWO_WEEKS);
    $sundayOne = collect($history['cells'])->first(fn (array $cell): bool => $cell['weekday'] === 7 && $cell['hour'] === 1);
    $sundayTwo = collect($history['cells'])->first(fn (array $cell): bool => $cell['weekday'] === 7 && $cell['hour'] === 2);
    $sundayThree = collect($history['cells'])->first(fn (array $cell): bool => $cell['weekday'] === 7 && $cell['hour'] === 3);

    expect($sundayOne['sampleCount'])->toBe(1)
        ->and($sundayThree['sampleCount'])->toBe(1)
        ->and($sundayTwo['sampleCount'])->toBe(2)
        ->and($sundayTwo['combinedAverage'])->toBe(3.0);
});

it('averages repeated local slots by their own sample count', function (): void {
    CarbonImmutable::setTestNow('2026-09-15 00:00:00 UTC');
    createPublicTrafficHour('2026-09-01 00:00:00', combined: 2, landingPages: 1, portal: 2);
    createPublicTrafficHour('2026-09-08 00:00:00', combined: 6, landingPages: 3, portal: 5);

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::FOUR_WEEKS);
    $tuesdayTwo = collect($history['cells'])->first(
        fn (array $cell): bool => $cell['weekday'] === 2 && $cell['hour'] === 2,
    );

    expect($tuesdayTwo['sampleCount'])->toBe(2)
        ->and($tuesdayTwo['combinedAverage'])->toBe(4.0)
        ->and($tuesdayTwo['landingPageAverage'])->toBe(2.0)
        ->and($tuesdayTwo['portalAverage'])->toBe(3.5);
});

it('uses stable weekday and hour ordering to resolve ranking ties', function (): void {
    CarbonImmutable::setTestNow('2026-09-08 00:00:00 UTC');
    $start = CarbonImmutable::now('UTC')->subWeek();

    for ($offset = 0; $offset < 168; $offset++) {
        createPublicTrafficHour($start->addHours($offset), combined: 5);
    }

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::FOUR_WEEKS);

    foreach (['quietest', 'busiest'] as $ranking) {
        expect(array_map(
            static fn (array $cell): array => [$cell['weekday'], $cell['hour']],
            $history[$ranking],
        ))->toBe([[1, 0], [1, 1], [1, 2]]);
    }
});

it('does not count hours before collection started as excluded gaps', function (): void {
    CarbonImmutable::setTestNow('2026-09-09 15:00:00 UTC');
    createPublicTrafficHour('2026-09-09 13:00:00', combined: 2);

    $history = app(PublicTrafficHistoryService::class)->history(PublicTrafficPeriod::FOUR_WEEKS);

    expect($history['requestedFrom'])->toBe('2026-08-12T15:00:00+00:00')
        ->and($history['effectiveFrom'])->toBe('2026-09-09T13:00:00+00:00')
        ->and($history['collectionStartedAt'])->toBe('2026-09-09T13:00:00+00:00')
        ->and($history['coverage']['completeHours'])->toBe(1)
        ->and($history['coverage']['excludedHours'])->toBe(1);
});

it('exposes period lengths and start calculations', function (): void {
    $end = CarbonImmutable::parse('2026-09-09 12:00:00 UTC');

    expect(PublicTrafficPeriod::FOUR_WEEKS->weeks())->toBe(4)
        ->and(PublicTrafficPeriod::TWELVE_WEEKS->weeks())->toBe(12)
        ->and(PublicTrafficPeriod::FIFTY_TWO_WEEKS->weeks())->toBe(52)
        ->and(PublicTrafficPeriod::FOUR_WEEKS->startsAt($end)->toIso8601String())->toBe('2026-08-12T12:00:00+00:00');
});
