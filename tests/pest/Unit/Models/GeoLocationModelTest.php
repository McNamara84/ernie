<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $resourceType = ResourceType::create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $language = Language::create([
        'code' => 'en',
        'name' => 'English',
    ]);

    $this->resource = Resource::create([
        'doi' => '10.5880/TEST.GEO.001',
        'publication_year' => 2025,
        'version' => '1.0',
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
    ]);
});

describe('GeoLocation model CRUD', function () {
    test('resource can have geo location point', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_longitude' => 11.527029,
            'point_latitude' => 48.167961,
        ]);

        $this->assertDatabaseHas('geo_locations', [
            'id' => $geoLocation->id,
            'resource_id' => $this->resource->id,
            'point_longitude' => '11.52702900',
            'point_latitude' => '48.16796100',
        ]);

        expect($this->resource->fresh()->geoLocations)->toHaveCount(1);
        expect($geoLocation->hasPoint())->toBeTrue();
        expect($geoLocation->hasBox())->toBeFalse();
    });

    test('resource can have geo location box', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'west_bound_longitude' => 11.400000,
            'east_bound_longitude' => 11.600000,
            'south_bound_latitude' => 48.100000,
            'north_bound_latitude' => 48.200000,
        ]);

        $this->assertDatabaseHas('geo_locations', [
            'id' => $geoLocation->id,
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->hasBox())->toBeTrue();
        expect($geoLocation->hasPoint())->toBeFalse();
    });

    test('resource can have geo location place', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam, Germany',
        ]);

        $this->assertDatabaseHas('geo_locations', [
            'id' => $geoLocation->id,
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam, Germany',
        ]);

        expect($geoLocation->hasPlace())->toBeTrue();
    });

    test('resource can have complete geo location', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'place' => 'GFZ Potsdam',
            'point_longitude' => 13.066035,
            'point_latitude' => 52.380002,
            'west_bound_longitude' => 13.050000,
            'east_bound_longitude' => 13.080000,
            'south_bound_latitude' => 52.370000,
            'north_bound_latitude' => 52.390000,
        ]);

        $freshGeoLocation = GeoLocation::find($geoLocation->id);

        expect($freshGeoLocation->hasPoint())->toBeTrue()
            ->and($freshGeoLocation->hasBox())->toBeTrue()
            ->and($freshGeoLocation->hasPlace())->toBeTrue()
            ->and($freshGeoLocation->place)->toBe('GFZ Potsdam');
    });

    test('resource can have multiple geo locations', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_longitude' => 11.527029,
            'point_latitude' => 48.167961,
            'place' => 'Munich, Germany',
        ]);

        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_longitude' => 13.404954,
            'point_latitude' => 52.520008,
            'place' => 'Berlin, Germany',
        ]);

        expect($this->resource->fresh()->geoLocations)->toHaveCount(2);
    });

    test('resource can have polygon geo location', function () {
        $polygonPoints = [
            ['longitude' => 13.0, 'latitude' => 52.0],
            ['longitude' => 14.0, 'latitude' => 52.0],
            ['longitude' => 14.0, 'latitude' => 53.0],
            ['longitude' => 13.0, 'latitude' => 53.0],
        ];

        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'polygon_points' => $polygonPoints,
            'in_polygon_point_longitude' => 13.5,
            'in_polygon_point_latitude' => 52.5,
        ]);

        $freshGeoLocation = GeoLocation::find($geoLocation->id);

        expect($freshGeoLocation->hasPolygon())->toBeTrue();
        expect($freshGeoLocation->polygon_points)->toHaveCount(4);
    });

    test('deleting resource cascades to geo locations', function () {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_longitude' => 11.527029,
            'point_latitude' => 48.167961,
        ]);

        $this->assertDatabaseHas('geo_locations', ['id' => $geoLocation->id]);

        $this->resource->delete();

        $this->assertDatabaseMissing('geo_locations', ['id' => $geoLocation->id]);
    });
});

describe('GeoLocation factory', function () {
    test('factory creates valid point', function () {
        $geoLocation = GeoLocation::factory()->withPoint()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->hasPoint())->toBeTrue();
        expect($geoLocation->point_longitude)->not->toBeNull();
        expect($geoLocation->point_latitude)->not->toBeNull();
    });

    test('factory creates valid box', function () {
        $geoLocation = GeoLocation::factory()->withBox()->create([
            'resource_id' => $this->resource->id,
        ]);

        expect($geoLocation->hasBox())->toBeTrue();
    });
});
