<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;

uses()->group('landing-pages', 'public', 'geo-locations');

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    $this->landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $this->resource->id,
        'template' => 'default_gfz',
    ]);
});

describe('Landing Page with GeoLocations', function () {
    test('returns geo_locations in resource data', function () {
        // Create a point location
        GeoLocation::factory()->withPoint(13.0661, 52.3806)->create([
            'resource_id' => $this->resource->id,
            'place' => 'GFZ Potsdam',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.place', 'GFZ Potsdam')
                ->where('resource.geo_locations.0.point_longitude', '13.06610000')
                ->where('resource.geo_locations.0.point_latitude', '52.38060000')
            );
    });

    test('returns multiple geo_locations', function () {
        // Create multiple locations
        GeoLocation::factory()->withPoint(13.0661, 52.3806)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam',
        ]);

        GeoLocation::factory()->withPoint(13.405, 52.52)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Berlin',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 2)
            );
    });

    test('returns bounding box coordinates', function () {
        GeoLocation::factory()->withBox(5.87, 15.04, 47.27, 55.06)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Germany',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.west_bound_longitude', '5.87000000')
                ->where('resource.geo_locations.0.east_bound_longitude', '15.04000000')
                ->where('resource.geo_locations.0.south_bound_latitude', '47.27000000')
                ->where('resource.geo_locations.0.north_bound_latitude', '55.06000000')
            );
    });

    test('returns polygon points as array', function () {
        $polygonPoints = [
            ['longitude' => 9.19, 'latitude' => 47.66],
            ['longitude' => 9.37, 'latitude' => 47.5],
            ['longitude' => 9.63, 'latitude' => 47.5],
            ['longitude' => 9.19, 'latitude' => 47.66],
        ];

        GeoLocation::factory()->withPolygon($polygonPoints)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Lake Constance',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->has('resource.geo_locations.0.polygon_points', 4)
            );
    });

    test('returns empty geo_locations array when none exist', function () {
        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 0)
            );
    });

    test('returns geo_location with only place name', function () {
        GeoLocation::factory()->create([
            'resource_id' => $this->resource->id,
            'place' => 'North Atlantic Ocean',
            'point_longitude' => null,
            'point_latitude' => null,
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.place', 'North Atlantic Ocean')
                ->where('resource.geo_locations.0.point_longitude', null)
                ->where('resource.geo_locations.0.point_latitude', null)
            );
    });

    test('returns mixed geo_location types', function () {
        // Point
        GeoLocation::factory()->withPoint(13.405, 52.52)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Berlin',
        ]);

        // Bounding Box
        GeoLocation::factory()->withBox(8.97, 13.84, 47.27, 50.56)->create([
            'resource_id' => $this->resource->id,
            'place' => 'Bavaria',
        ]);

        // Polygon
        GeoLocation::factory()->withPolygon([
            ['longitude' => 10, 'latitude' => 47.5],
            ['longitude' => 12, 'latitude' => 47],
            ['longitude' => 14, 'latitude' => 47.5],
            ['longitude' => 10, 'latitude' => 47.5],
        ])->create([
            'resource_id' => $this->resource->id,
            'place' => 'Alps',
        ]);

        $response = $this->get("/datasets/{$this->resource->id}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 3)
            );
    });
});
