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
 * Formerly ResourceCoverageTest - updated for DataCite 4.6 schema
 */
class ResourceCoverageTest extends TestCase
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
            'doi' => '10.5880/TEST.SETUP.001',
            'publication_year' => 2025,
            'version' => '1.0',
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
        ]);
    }

    public function test_resource_can_have_spatial_coverage(): void
    {
        $coverage = ResourceCoverage::create([
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

        $this->assertEquals(1, $this->resource->fresh()->coverages->count());
    }

    public function test_resource_can_have_temporal_coverage(): void
    {
        $coverage = ResourceCoverage::create([
            'resource_id' => $this->resource->id,
            'start_date' => '2025-10-06',
            'end_date' => '2025-10-10',
            'start_time' => '15:01:00',
            'end_time' => '16:01:00',
            'timezone' => 'Europe/Berlin',
        ]);

        // Database stores dates with time component
        $this->assertDatabaseHas('resource_coverages', [
            'id' => $coverage->id,
            'resource_id' => $this->resource->id,
            'start_time' => '15:01:00',
            'end_time' => '16:01:00',
        ]);

        // Model returns Carbon instances
        $this->assertEquals('2025-10-06', $coverage->start_date->toDateString());
        $this->assertEquals('2025-10-10', $coverage->end_date->toDateString());
    }

    public function test_resource_can_have_both_spatial_and_temporal_coverage(): void
    {
        $coverage = ResourceCoverage::create([
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

        // Check that all data is stored
        $freshCoverage = ResourceCoverage::find($coverage->id);

        $this->assertEquals('48.167961', (string) $freshCoverage->lat_min);
        $this->assertEquals('48.200000', (string) $freshCoverage->lat_max);
        $this->assertEquals('11.527029', (string) $freshCoverage->lon_min);
        $this->assertEquals('11.600000', (string) $freshCoverage->lon_max);
        $this->assertEquals('2025-10-06', $freshCoverage->start_date->toDateString());
        $this->assertEquals('2025-10-10', $freshCoverage->end_date->toDateString());
        $this->assertEquals('Test coverage area', $freshCoverage->description);
    }

    public function test_resource_can_have_multiple_coverages(): void
    {
        ResourceCoverage::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'timezone' => 'UTC',
        ]);

        ResourceCoverage::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 52.520008,
            'lon_min' => 13.404954,
            'timezone' => 'UTC',
        ]);

        $this->assertEquals(2, $this->resource->fresh()->coverages->count());
    }

    public function test_resource_index_includes_coverages(): void
    {
        $this->actingAsUser();

        ResourceCoverage::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'start_date' => '2025-10-06',
            'end_date' => '2025-10-10',
            'timezone' => 'Europe/Berlin',
            'description' => 'Test coverage',
        ]);

        $response = $this->get('/resources');

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
    }

    public function test_deleting_resource_cascades_to_coverages(): void
    {
        $coverage = ResourceCoverage::create([
            'resource_id' => $this->resource->id,
            'lat_min' => 48.167961,
            'lon_min' => 11.527029,
            'timezone' => 'UTC',
        ]);

        $this->assertDatabaseHas('resource_coverages', ['id' => $coverage->id]);

        $this->resource->delete();

        $this->assertDatabaseMissing('resource_coverages', ['id' => $coverage->id]);
    }

    private function actingAsUser(): self
    {
        $user = \App\Models\User::factory()->create();

        return $this->actingAs($user);
    }
}
