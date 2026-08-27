<?php

declare(strict_types=1);

use App\Services\TemporalCoverageValueService;

beforeEach(function (): void {
    $this->service = new TemporalCoverageValueService;
});

it('parses sparse and precise temporal coverage values', function (string $value, array $expected): void {
    expect($this->service->parse($value))->toMatchArray($expected);
})->with([
    'date-only interval' => ['2026-08-25/2026-08-27', [
        'startDate' => '2026-08-25', 'endDate' => '2026-08-27',
        'startTime' => '', 'endTime' => '', 'timezone' => '', 'temporalMode' => 'interval',
    ]],
    'datetime and offset' => ['2026-08-25T14:37:00+02:00/2026-08-27T17:37:42+02:00', [
        'startDate' => '2026-08-25', 'endDate' => '2026-08-27',
        'startTime' => '14:37', 'endTime' => '17:37:42', 'timezone' => '+02:00', 'temporalMode' => 'interval',
    ]],
    'start only' => ['2026-08-25/', [
        'startDate' => '2026-08-25', 'endDate' => '', 'timezone' => '', 'temporalMode' => 'interval',
    ]],
    'end only' => ['/2026-08-27T17:37Z', [
        'startDate' => '', 'endDate' => '2026-08-27', 'endTime' => '17:37', 'timezone' => 'UTC', 'temporalMode' => 'interval',
    ]],
    'reduced-precision interval' => ['2026/2026-08', [
        'startDate' => '2026', 'endDate' => '2026-08', 'temporalMode' => 'interval',
    ]],
    'single instant' => ['2026-05', [
        'startDate' => '2026-05', 'endDate' => '', 'temporalMode' => 'instant',
    ]],
]);

it('normalizes only supported timezone representations', function (): void {
    expect($this->service->normalizeTimezone('Z'))->toBe('UTC')
        ->and($this->service->normalizeTimezone('+09:00'))->toBe('+09:00')
        ->and($this->service->normalizeTimezone('Europe/Berlin'))->toBe('Europe/Berlin')
        ->and($this->service->normalizeTimezone('+14:30'))->toBeNull()
        ->and($this->service->normalizeTimezone('Not/AZone'))->toBeNull();
});

it('rejects impossible dates, times, and offsets', function (string $value): void {
    expect($this->service->parse($value))->toBe([
        'startDate' => '',
        'endDate' => '',
        'startTime' => '',
        'endTime' => '',
        'timezone' => '',
        'temporalMode' => '',
    ]);
})->with([
    'impossible date' => '2026-02-30',
    'impossible time' => '2026-08-25T25:00+02:00',
    'impossible offset' => '2026-08-25T14:37+14:30',
]);

it('serializes open DataCite intervals without inventing a timezone', function (): void {
    expect($this->service->toDataCiteValue([
        'startDate' => '2026-08-25',
        'startTime' => '14:37',
        'timezone' => '+02:00',
    ]))->toBe('2026-08-25T14:37+02:00/')
        ->and($this->service->toDataCiteValue([
            'endDate' => '2026-08-27',
            'endTime' => '17:37',
        ]))->toBe('/2026-08-27');
});

it('preserves instant versus open-interval semantics when serializing', function (): void {
    expect($this->service->toDataCiteValue([
        'startDate' => '2026-05',
        'temporalMode' => 'instant',
    ]))->toBe('2026-05')
        ->and($this->service->toDataCiteValue([
            'startDate' => '2026-05',
            'temporalMode' => 'interval',
        ]))->toBe('2026-05/')
        ->and($this->service->toIsoEndpoints([
            'startDate' => '2026',
            'temporalMode' => 'instant',
        ]))->toBe([
            'start' => '2026',
            'end' => '',
            'mode' => 'instant',
        ]);
});
