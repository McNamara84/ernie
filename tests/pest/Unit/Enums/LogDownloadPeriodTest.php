<?php

declare(strict_types=1);

use App\Enums\LogDownloadPeriod;
use Carbon\CarbonImmutable;

covers(LogDownloadPeriod::class);

it('calculates rolling UTC period boundaries', function (LogDownloadPeriod $period, string $expectedStart): void {
    $endsAt = CarbonImmutable::parse('2026-09-02 14:30:15', 'UTC');

    expect($period->startsAt($endsAt)->toIso8601String())->toBe($expectedStart)
        ->and($endsAt->toIso8601String())->toBe('2026-09-02T14:30:15+00:00');
})->with([
    'day' => [LogDownloadPeriod::DAY, '2026-09-01T14:30:15+00:00'],
    'week' => [LogDownloadPeriod::WEEK, '2026-08-26T14:30:15+00:00'],
    'month' => [LogDownloadPeriod::MONTH, '2026-08-03T14:30:15+00:00'],
]);

it('provides stable labels and filename segments', function (
    LogDownloadPeriod $period,
    string $label,
    string $filenameSegment,
): void {
    expect($period->label())->toBe($label)
        ->and($period->filenameSegment())->toBe($filenameSegment);
})->with([
    'day' => [LogDownloadPeriod::DAY, 'Last 24 hours', 'last-24-hours'],
    'week' => [LogDownloadPeriod::WEEK, 'Last 7 days', 'last-7-days'],
    'month' => [LogDownloadPeriod::MONTH, 'Last 30 days', 'last-30-days'],
]);
