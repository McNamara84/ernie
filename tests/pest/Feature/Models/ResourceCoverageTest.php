<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\User;

/*
 * NOTE: The original test references a `ResourceCoverage` class which does not exist.
 * The actual model is `GeoLocation` (DataCite #18 – GeoLocation).
 * The original table name `resource_coverages` and relationship `coverages` are preserved
 * as they appeared in the source test. These may need adjustment to match the current schema
 * (e.g., table `geo_locations`, relationship `geoLocations`).
 */

covers(GeoLocation::class);

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
        'doi' => '10.5880/TEST.SETUP.001',
        'publication_year' => 2025,
        'version' => '1.0',
        'resource_type_id' => $resourceType->id,
        'language_id' => $language->id,
    ]);
});

describe('Spatial coverage', function () {
    it('can add spatial coverage to a resource', function () {
        $coverage = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertDatabaseHas('resource_coverages', [
            'id' => $coverage->id,
            'resource_id' => $this->resource->id,
            'lat_min' => '48.167961',
            'lon_min' => '11.527029',
        ]);

        expect($this->resource->fresh()->coverages)->toHaveCount(1);
    });
});

describe('Temporal coverage', function () {
    it('can add temporal coverage to a resource', function () {
        $coverage = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'start_date' => '2025-10-06',
            'end_date' => '2025-10-10',
            'start_time' => '15:01:00',
            'end_time' => '16:01:00',
            'timezone' => 'Europe/Berlin',
        ]);

        $this->assertDatabaseHas('resource_coverages', [
            'id' => $coverage->id,
            'resource_id' => $this->resource->id,
            'start_time' => '15:01:00',
            'end_time' => '16:01:00',
        ]);

        expect($coverage->start_date->toDateString())->toBe('2025-10-06')
            ->and($coverage->end_date->toDateString())->toBe('2025-10-10');
    });
});

describe('Combined spatial and temporal coverage', function () {
    it('stores both spatial and temporal data together', function () {
        $coverage = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lat_max' => 48.200000,
            'lon_min' => 11.527029,
            'lon_max' => 11.600000,
            'start_date' => '2025-10-06',
            'end_date' => '2025-10-10',
            'start_time' => '15:01:00',
            'end_time' => '16:01:00',
            'timezone' => 'Europe/Berlin',
            'description' => 'Test coverage area',
        ]);

        $freshCoverage = GeoLocation::find($coverage->id);

        expect((string) $freshCoverage->lat_min)->toBe('48.167961')
            ->and((string) $freshCoverage->lat_max)->toBe('48.200000')
            ->and((string) $freshCoverage->lon_min)->toBe('11.527029')
            ->and((string) $freshCoverage->lon_max)->toBe('11.600000')
            ->and($freshCoverage->start_date->toDateString())->toBe('2025-10-06')
            ->and($freshCoverage->end_date->toDateString())->toBe('2025-10-10')
            ->and($freshCoverage->description)->toBe('Test coverage area');
    });
});

describe('Multiple coverages', function () {
    it('allows a resource to have multiple coverages', function () {
        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'timezone' => 'UTC',
        ]);

        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 52.520008,
            'lon_min' => 13.404954,
            'timezone' => 'UTC',
        ]);

        expect($this->resource->fresh()->coverages)->toHaveCount(2);
    });
});

describe('Resource index integration', function () {
    it('includes coverages on the resource index page', function () {
        $user = User::factory()->create();

        GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'start_date' => '2025-10-06',
            'end_date' => '2025-10-10',
            'timezone' => 'Europe/Berlin',
            'description' => 'Test coverage',
        ]);

        $response = $this->actingAs($user)->get('/resources');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('resources')
            ->has('resources', 1)
            ->where('resources.0.spatialTemporalCoverages.0.latMin', '48.167961')
            ->where('resources.0.spatialTemporalCoverages.0.lonMin', '11.527029')
            ->where('resources.0.spatialTemporalCoverages.0.startDate', '2025-10-06')
            ->where('resources.0.spatialTemporalCoverages.0.endDate', '2025-10-10')
            ->where('resources.0.spatialTemporalCoverages.0.timezone', 'Europe/Berlin')
            ->where('resources.0.spatialTemporalCoverages.0.description', 'Test coverage')
        );
    });
});

describe('Cascade deletion', function () {
    it('deletes coverages when the resource is deleted', function () {
        $coverage = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'timezone' => 'UTC',
        ]);

        $this->assertDatabaseHas('resource_coverages', ['id' => $coverage->id]);

        $this->resource->delete();

        $this->assertDatabaseMissing('resource_coverages', ['id' => $coverage->id]);
    });
});
