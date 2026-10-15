<?php

declare(strict_types=1);

use App\Http\Controllers\LandingPagePublicController;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Resource;

covers(LandingPagePublicController::class);

uses()->group('landing-pages', 'public', 'geo-locations');

beforeEach(function () {
    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/test.geo.001',
    ]);
    $this->landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $this->resource->id,
        'doi_prefix' => '10.5880/test.geo.001',
        'slug' => 'geo-test-dataset',
        'template' => 'default_gfz',
    ]);

    // Helper to get semantic URL
    $this->landingPageUrl = "/{$this->landingPage->doi_prefix}/{$this->landingPage->slug}";
});

describe('Landing Page with GeoLocations', function () {
    test('normalizes stored numeric-string paths in published and preview payloads without rewriting data', function (string $type, bool $preview) {
        $points = [['latitude' => '52.38', 'longitude' => '13.06'],
            ['latitude' => '52.40', 'longitude' => '13.08'], ['latitude' => 53.25, 'longitude' => '13.2']];
        $location = GeoLocation::factory()->create(['resource_id' => $this->resource->id, 'geo_type' => $type, 'polygon_points' => $points]);
        $url = $this->landingPageUrl;
        if ($preview) {
            $this->landingPage->update(['is_published' => false]);
            $url .= '?preview='.$this->landingPage->preview_token;
        }

        $this->get($url)->assertOk()->assertInertia(fn ($page) => $page
            ->where('resource.geo_locations.0.geo_type', $type)
            ->where('resource.geo_locations.0.polygon_points', [
                ['latitude' => 52.38, 'longitude' => 13.06],
                ['latitude' => 52.4, 'longitude' => 13.08], ['latitude' => 53.25, 'longitude' => 13.2],
            ]));
        expect($location->fresh()->polygon_points)->toBe($points);
    })->with(['polygon', 'line'])->with([false, true]);

    test('returns geo_locations in resource data', function () {
        // Create a point location
        GeoLocation::factory()->withPoint(13.0661, 52.3806)->create([
            'resource_id' => $this->resource->id,
            'place' => 'GFZ Potsdam',
        ]);

        $response = $this->get($this->landingPageUrl);

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->component('LandingPages/default_gfz')
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.place', 'GFZ Potsdam')
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

        $response = $this->get("{$this->landingPageUrl}");

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

        $response = $this->get("{$this->landingPageUrl}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.west_bound_longitude', 5.87)
                ->where('resource.geo_locations.0.east_bound_longitude', 15.04)
                ->where('resource.geo_locations.0.south_bound_latitude', 47.27)
                ->where('resource.geo_locations.0.north_bound_latitude', 55.06)
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

        $response = $this->get("{$this->landingPageUrl}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->has('resource.geo_locations.0.polygon_points', 4)
            );
    });

    test('returns a line with its complete ordered point sequence', function () {
        $linePoints = [
            ['longitude' => 11.77626257, 'latitude' => 49.80986519],
            ['longitude' => 12, 'latitude' => 49.9],
            ['longitude' => 12.31280322, 'latitude' => 50.05294114],
        ];

        GeoLocation::factory()->withLine($linePoints)->create([
            'resource_id' => $this->resource->id,
            'place' => 'DEKORP profile',
        ]);

        $response = $this->get($this->landingPageUrl);

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 1)
                ->where('resource.geo_locations.0.geo_type', 'line')
                ->where('resource.geo_locations.0.place', 'DEKORP profile')
                ->where('resource.geo_locations.0.polygon_points', $linePoints)
            );
    });

    test('returns empty geo_locations array when none exist', function () {
        $response = $this->get("{$this->landingPageUrl}");

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

        $response = $this->get("{$this->landingPageUrl}");

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

        $response = $this->get("{$this->landingPageUrl}");

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->has('resource.geo_locations', 3)
            );
    });
});
