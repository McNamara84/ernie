<?php

declare(strict_types=1);

use App\Services\Legacy\LegacyCoverageGeometryParserService;

it('parses whitespace separated legacy line coordinate chains', function (): void {
    $points = (new LegacyCoverageGeometryParserService)->parseLine('8.1 49.2 8.3 49.4');

    expect($points)->toBe([
        ['lat' => 49.2, 'lon' => 8.1],
        ['lat' => 49.4, 'lon' => 8.3],
    ]);
});

it('parses comma separated legacy line coordinate chains', function (): void {
    $points = (new LegacyCoverageGeometryParserService)->parseLine('8.1, 49.2, 8.3, 49.4');

    expect($points)->toBe([
        ['lat' => 49.2, 'lon' => 8.1],
        ['lat' => 49.4, 'lon' => 8.3],
    ]);
});

it('parses LINESTRING WKT values', function (): void {
    $points = (new LegacyCoverageGeometryParserService)->parseLine('LINESTRING (8.1 49.2, 8.3 49.4)');

    expect($points)->toBe([
        ['lat' => 49.2, 'lon' => 8.1],
        ['lat' => 49.4, 'lon' => 8.3],
    ]);
});

it('returns null for unsupported or malformed geometry', function (string $geometry): void {
    expect((new LegacyCoverageGeometryParserService)->parseLine($geometry))->toBeNull();
})->with([
    'odd coordinate count' => ['8.1 49.2 8.3'],
    'ocr typo in numeric token' => ['13.O57855 49.2 8.3 49.4'],
    'unsupported WKT type' => ['POLYGON ((8.1 49.2, 8.3 49.4, 8.1 49.2))'],
    'out of range latitude' => ['8.1 149.2 8.3 49.4'],
]);
