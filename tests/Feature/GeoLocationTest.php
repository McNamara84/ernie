<?php

namespace Tests\Feature;

use App\Models\GeoLocation;
use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for GeoLocation model (DataCite #18)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/geolocation/
 */
class GeoLocationTest extends TestCase
{
    use RefreshDatabase;

    private Resource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        // Create required related records directly
        $resourceType = ResourceType::create([
            'name' => 'Dataset',
            'slug' => 'dataset',
        ]);

        $language = Language::create([
            'code' => 'en',
            'name' => 'English',
        ]);

        // Create a test resource
        $this->resource = Resource::create([
            'doi' => '10.5880/TEST.GEO.001',
            'publication_year' => 2025,
            'version' => '1.0',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
        ]);
    }

    public function test_resource_can_have_geo_location_point(): void
    {
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

        $this->assertEquals(1, $this->resource->fresh()->geoLocations->count());
        $this->assertTrue($geoLocation->hasPoint());
        $this->assertFalse($geoLocation->hasBox());
    }

    public function test_resource_can_have_geo_location_box(): void
    {
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

        $this->assertTrue($geoLocation->hasBox());
        $this->assertFalse($geoLocation->hasPoint());
    }

    public function test_resource_can_have_geo_location_place(): void
    {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam, Germany',
        ]);

        $this->assertDatabaseHas('geo_locations', [
            'id' => $geoLocation->id,
            'resource_id' => $this->resource->id,
            'place' => 'Potsdam, Germany',
        ]);

        $this->assertTrue($geoLocation->hasPlace());
    }

    public function test_resource_can_have_complete_geo_location(): void
    {
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

        $this->assertTrue($freshGeoLocation->hasPoint());
        $this->assertTrue($freshGeoLocation->hasBox());
        $this->assertTrue($freshGeoLocation->hasPlace());
        $this->assertEquals('GFZ Potsdam', $freshGeoLocation->place);
    }

    public function test_resource_can_have_multiple_geo_locations(): void
    {
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

        $this->assertEquals(2, $this->resource->fresh()->geoLocations->count());
    }

    public function test_resource_can_have_polygon_geo_location(): void
    {
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

        $this->assertTrue($freshGeoLocation->hasPolygon());
        $this->assertCount(4, $freshGeoLocation->polygon_points);
    }

    public function test_deleting_resource_cascades_to_geo_locations(): void
    {
        $geoLocation = GeoLocation::create([
            'resource_id' => $this->resource->id,
            'point_longitude' => 11.527029,
            'point_latitude' => 48.167961,
        ]);

        $this->assertDatabaseHas('geo_locations', ['id' => $geoLocation->id]);

        $this->resource->delete();

        $this->assertDatabaseMissing('geo_locations', ['id' => $geoLocation->id]);
    }

    public function test_geo_location_factory_creates_valid_point(): void
    {
        $geoLocation = GeoLocation::factory()->withPoint()->create([
            'resource_id' => $this->resource->id,
        ]);

        $this->assertTrue($geoLocation->hasPoint());
        $this->assertNotNull($geoLocation->point_longitude);
        $this->assertNotNull($geoLocation->point_latitude);
    }

    public function test_geo_location_factory_creates_valid_box(): void
    {
        $geoLocation = GeoLocation::factory()->withBox()->create([
            'resource_id' => $this->resource->id,
        ]);

        $this->assertTrue($geoLocation->hasBox());
    }
}
