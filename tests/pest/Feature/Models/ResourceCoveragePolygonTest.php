<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceCoverage;

describe('ResourceCoverage Polygon Support', function () {
    test('polygon points are cast to array', function () {
        $coverage = ResourceCoverage::factory()->create([
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 41.090, 'lon' => -71.032],
                ['lat' => 41.090, 'lon' => -68.211],
                ['lat' => 39.399, 'lon' => -68.211],
            ],
        ]);

        expect($coverage->polygon_points)
            ->toBeArray()
            ->toHaveCount(3)
            ->and($coverage->polygon_points[0])
            ->toHaveKeys(['lat', 'lon'])
            ->and($coverage->polygon_points[0]['lat'])
            ->toBe(41.090)
            ->and($coverage->polygon_points[0]['lon'])
            ->toBe(-71.032);
    });

    test('validates polygon has at least 3 points', function () {
        $coverage = ResourceCoverage::factory()->make([
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 41.090, 'lon' => -71.032],
                ['lat' => 41.090, 'lon' => -68.211],
            ],
        ]);

        expect($coverage->validatePolygon())->toBeFalse();
    });

    test('validates polygon with 3 points passes', function () {
        $coverage = ResourceCoverage::factory()->make([
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 41.090, 'lon' => -71.032],
                ['lat' => 41.090, 'lon' => -68.211],
                ['lat' => 39.399, 'lon' => -68.211],
            ],
        ]);

        expect($coverage->validatePolygon())->toBeTrue();
    });

    test('validates polygon with 4+ points passes', function () {
        $coverage = ResourceCoverage::factory()->polygon()->make();

        expect($coverage->validatePolygon())->toBeTrue();
    });

    test('validation returns true for non-polygon types', function () {
        $point = ResourceCoverage::factory()->make(['type' => 'point']);
        $box = ResourceCoverage::factory()->box()->make();

        expect($point->validatePolygon())->toBeTrue()
            ->and($box->validatePolygon())->toBeTrue();
    });

    test('validation returns true when polygon_points is null', function () {
        $coverage = ResourceCoverage::factory()->make([
            'type' => 'polygon',
            'polygon_points' => null,
        ]);

        expect($coverage->validatePolygon())->toBeTrue();
    });

    test('getClosedPolygonPoints adds closing point if needed', function () {
        $coverage = ResourceCoverage::factory()->create([
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 41.090, 'lon' => -71.032],
                ['lat' => 41.090, 'lon' => -68.211],
                ['lat' => 39.399, 'lon' => -68.211],
            ],
        ]);

        $closedPoints = $coverage->getClosedPolygonPoints();

        expect($closedPoints)
            ->toHaveCount(4)
            ->and($closedPoints[0])
            ->toBe($closedPoints[3]) // First equals last
            ->and($closedPoints[0]['lat'])
            ->toBe(41.090)
            ->and($closedPoints[0]['lon'])
            ->toBe(-71.032);
    });

    test('getClosedPolygonPoints does not duplicate if already closed', function () {
        $coverage = ResourceCoverage::factory()->create([
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 41.090, 'lon' => -71.032],
                ['lat' => 41.090, 'lon' => -68.211],
                ['lat' => 39.399, 'lon' => -68.211],
                ['lat' => 41.090, 'lon' => -71.032], // Already closed
            ],
        ]);

        $closedPoints = $coverage->getClosedPolygonPoints();

        expect($closedPoints)->toHaveCount(4);
    });

    test('getClosedPolygonPoints returns empty array for non-polygon types', function () {
        $point = ResourceCoverage::factory()->create(['type' => 'point']);
        $box = ResourceCoverage::factory()->box()->create();

        expect($point->getClosedPolygonPoints())
            ->toBeArray()
            ->toBeEmpty()
            ->and($box->getClosedPolygonPoints())
            ->toBeArray()
            ->toBeEmpty();
    });

    test('getClosedPolygonPoints returns empty array when polygon_points is null', function () {
        $coverage = ResourceCoverage::factory()->create([
            'type' => 'polygon',
            'polygon_points' => null,
        ]);

        expect($coverage->getClosedPolygonPoints())
            ->toBeArray()
            ->toBeEmpty();
    });

    test('polygon factory creates valid polygon with 4 points', function () {
        $coverage = ResourceCoverage::factory()->polygon()->create();

        expect($coverage->type)->toBe('polygon')
            ->and($coverage->polygon_points)->toHaveCount(4)
            ->and($coverage->lat_min)->toBeNull()
            ->and($coverage->lat_max)->toBeNull()
            ->and($coverage->lon_min)->toBeNull()
            ->and($coverage->lon_max)->toBeNull();
    });

    test('polygon coordinates are within valid ranges', function () {
        $coverage = ResourceCoverage::factory()->polygon()->create();

        foreach ($coverage->polygon_points as $point) {
            expect($point['lat'])->toBeGreaterThanOrEqual(-90)
                ->and($point['lat'])->toBeLessThanOrEqual(90)
                ->and($point['lon'])->toBeGreaterThanOrEqual(-180)
                ->and($point['lon'])->toBeLessThanOrEqual(180);
        }
    });

    test('polygon can be persisted and retrieved', function () {
        $resource = Resource::factory()->create();

        $coverage = ResourceCoverage::factory()->create([
            'resource_id' => $resource->id,
            'type' => 'polygon',
            'polygon_points' => [
                ['lat' => 52.520008, 'lon' => 13.404954], // Berlin
                ['lat' => 48.856613, 'lon' => 2.352222], // Paris
                ['lat' => 51.507351, 'lon' => -0.127758], // London
            ],
            'description' => 'European Triangle',
        ]);

        $retrieved = ResourceCoverage::find($coverage->id);

        expect($retrieved->type)->toBe('polygon')
            ->and($retrieved->polygon_points)->toHaveCount(3)
            ->and($retrieved->polygon_points[0]['lat'])->toBe(52.520008)
            ->and($retrieved->description)->toBe('European Triangle');
    });

    test('type defaults to point', function () {
        $coverage = ResourceCoverage::factory()->create([
            'lat_min' => 52.520008,
            'lon_min' => 13.404954,
        ]);

        expect($coverage->type)->toBe('point');
    });

    test('box factory creates bounding box with correct type', function () {
        $coverage = ResourceCoverage::factory()->box()->create();

        expect($coverage->type)->toBe('box')
            ->and($coverage->lat_min)->not->toBeNull()
            ->and($coverage->lat_max)->not->toBeNull()
            ->and($coverage->lon_min)->not->toBeNull()
            ->and($coverage->lon_max)->not->toBeNull()
            ->and($coverage->polygon_points)->toBeNull()
            ->and($coverage->lat_min)->toBeLessThanOrEqual($coverage->lat_max)
            ->and($coverage->lon_min)->toBeLessThanOrEqual($coverage->lon_max);
    });
});
