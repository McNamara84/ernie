<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Resource;

covers(GeoLocation::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
});

describe('Point location', function () {
    it('can add a point location to a resource', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 48.16796100,
            'point_longitude' => 11.52702900,
            'place' => 'Munich, Germany',
        ]);

        $this->assertDatabaseHas('geo_locations', [
            'id' => $geoLocation->id,
            'resource_id' => $this->resource->id,
            'place' => 'Munich, Germany',
        ]);

        expect($this->resource->fresh()->geoLocations)->toHaveCount(1);
    });
});

describe('Bounding box', function () {
    it('can add a bounding box location to a resource', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'west_bound_longitude' => 11.52702900,
            'east_bound_longitude' => 11.60000000,
            'south_bound_latitude' => 48.16796100,
            'north_bound_latitude' => 48.20000000,
        ]);

        $freshGeo = GeoLocation::find($geoLocation->id);

        expect((string) $freshGeo->west_bound_longitude)->toBe('11.52702900')
            ->and((string) $freshGeo->east_bound_longitude)->toBe('11.60000000')
            ->and((string) $freshGeo->south_bound_latitude)->toBe('48.16796100')
            ->and((string) $freshGeo->north_bound_latitude)->toBe('48.20000000');
    });
});

describe('Complete geo location', function () {
    it('stores point, bounding box and place together', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 48.16796100,
            'point_longitude' => 11.52702900,
            'west_bound_longitude' => 11.00000000,
            'east_bound_longitude' => 12.00000000,
            'south_bound_latitude' => 48.00000000,
            'north_bound_latitude' => 49.00000000,
            'place' => 'Test coverage area',
            'elevation' => 520.50,
            'elevation_unit' => 'm',
        ]);

        $freshGeo = GeoLocation::find($geoLocation->id);

        expect((string) $freshGeo->point_latitude)->toBe('48.16796100')
            ->and((string) $freshGeo->point_longitude)->toBe('11.52702900')
            ->and((string) $freshGeo->west_bound_longitude)->toBe('11.00000000')
            ->and((string) $freshGeo->east_bound_longitude)->toBe('12.00000000')
            ->and($freshGeo->place)->toBe('Test coverage area')
            ->and((string) $freshGeo->elevation)->toBe('520.50')
            ->and($freshGeo->elevation_unit)->toBe('m');
    });
});

describe('Multiple geo locations', function () {
    it('allows a resource to have multiple geo locations', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 48.16796100,
            'point_longitude' => 11.52702900,
        ]);

        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 52.52000800,
            'point_longitude' => 13.40495400,
        ]);

        expect($this->resource->fresh()->geoLocations)->toHaveCount(2);
    });
});

describe('Polygon support', function () {
    it('stores polygon points as JSON', function () {
        $polygonPoints = [
            ['longitude' => 11.0, 'latitude' => 48.0],
            ['longitude' => 12.0, 'latitude' => 48.0],
            ['longitude' => 12.0, 'latitude' => 49.0],
            ['longitude' => 11.0, 'latitude' => 49.0],
            ['longitude' => 11.0, 'latitude' => 48.0],
        ];

        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'polygon_points' => $polygonPoints,
            'in_polygon_point_longitude' => 11.50000000,
            'in_polygon_point_latitude' => 48.50000000,
        ]);

        $freshGeo = GeoLocation::find($geoLocation->id);

        expect($freshGeo->polygon_points)->toBeArray()->toHaveCount(5)
            ->and((string) $freshGeo->in_polygon_point_longitude)->toBe('11.50000000')
            ->and((string) $freshGeo->in_polygon_point_latitude)->toBe('48.50000000');
    });
});

describe('Cascade deletion', function () {
    it('deletes geo locations when the resource is deleted', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_latitude' => 48.16796100,
            'point_longitude' => 11.52702900,
        ]);

        $this->assertDatabaseHas('geo_locations', ['id' => $geoLocation->id]);

        $this->resource->delete();

        $this->assertDatabaseMissing('geo_locations', ['id' => $geoLocation->id]);
    });
});
