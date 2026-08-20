<?php

use App\Services\Igsn\IgsnGeometryNormalizer;

covers(IgsnGeometryNormalizer::class);

it('normalizes one pair to a point', function (): void {
    $result = (new IgsnGeometryNormalizer)->normalize([
        ['latitude' => '49.6288', 'longitude' => '8.68799'],
    ]);

    expect($result)->toBe([
        'geo_type' => 'point',
        'point_latitude' => 49.6288,
        'point_longitude' => 8.68799,
    ]);
});

it('normalizes two reversed pairs to numeric box bounds', function (): void {
    $result = (new IgsnGeometryNormalizer)->normalize([
        ['latitude' => '49.6344', 'longitude' => '8.69644'],
        ['latitude' => '49.6288', 'longitude' => '8.68799'],
    ]);

    expect($result)->toBe([
        'geo_type' => 'box',
        'south_bound_latitude' => 49.6288,
        'north_bound_latitude' => 49.6344,
        'west_bound_longitude' => 8.68799,
        'east_bound_longitude' => 8.69644,
    ]);
});

it('normalizes three distinct pairs to an ordered polygon and never a line', function (): void {
    $pairs = [
        ['latitude' => 1.0, 'longitude' => 2.0],
        ['latitude' => 3.0, 'longitude' => 4.0],
        ['latitude' => 5.0, 'longitude' => 6.0],
    ];

    $result = (new IgsnGeometryNormalizer)->normalize($pairs);

    expect($result['geo_type'])->toBe('polygon')
        ->and($result['polygon_points'])->toBe($pairs)
        ->and($result)->not->toHaveKey('point_latitude');
});

it('ignores incomplete invalid out-of-range and duplicate pairs', function (): void {
    $result = (new IgsnGeometryNormalizer)->normalize([
        ['latitude' => 'invalid', 'longitude' => 2],
        ['latitude' => 91, 'longitude' => 2],
        ['latitude' => 1, 'longitude' => 181],
        ['latitude' => 1, 'longitude' => 2],
        ['latitude' => '1.0', 'longitude' => '2.0'],
    ]);

    expect($result)->toBe([
        'geo_type' => 'point',
        'point_latitude' => 1.0,
        'point_longitude' => 2.0,
    ]);
});
