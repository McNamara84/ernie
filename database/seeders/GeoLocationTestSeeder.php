<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Language;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;

/**
 * Seeder for creating test resources with various GeoLocation configurations.
 * Used for testing the LocationSection component on landing pages.
 *
 * Usage: php artisan db:seed --class=GeoLocationTestSeeder
 *
 * DEVELOPMENT ONLY - Do not run in production!
 *
 * Creates resources with:
 * - Single point location (GFZ Potsdam)
 * - Single bounding box (Germany)
 * - Single polygon (Lake Constance)
 * - Multiple locations (mixed types)
 * - No geo locations (control case)
 * - Only place name, no coordinates (should not show map)
 */
class GeoLocationTestSeeder extends Seeder
{
    public function run(): void
    {
        // Prevent running in production environment
        if (App::environment('production')) {
            $this->command->error('This seeder cannot be run in production environment!');

            return;
        }

        $this->command->info('Creating GeoLocation test resources...');

        // Get or create required related models
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]
        );

        $titleType = TitleType::firstOrCreate(
            ['slug' => 'main-title'],
            ['name' => 'Main Title', 'slug' => 'main-title']
        );

        $abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'abstract'],
            ['name' => 'Abstract', 'slug' => 'abstract']
        );

        // Create test author
        $testPerson = Person::firstOrCreate(
            ['name_identifier' => '0000-0001-2345-6789'],
            [
                'given_name' => 'Test',
                'family_name' => 'Author',
                'name_identifier' => '0000-0001-2345-6789',
                'name_identifier_scheme' => 'ORCID',
            ]
        );

        // 1. Resource with single POINT (GFZ Potsdam)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Single Point (GFZ Potsdam)',
            doi: '10.5880/geoloc.point.001',
            abstract: 'Test resource with a single point location at GFZ Potsdam, Germany. The map should show a marker at the exact coordinates.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                [
                    'place' => 'GFZ German Research Centre for Geosciences, Potsdam',
                    'point_longitude' => 13.0661,
                    'point_latitude' => 52.3806,
                ],
            ]
        );

        // 2. Resource with single BOUNDING BOX (Germany)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Bounding Box (Germany)',
            doi: '10.5880/geoloc.box.001',
            abstract: 'Test resource with a bounding box covering Germany. The map should show a rectangle overlay.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                [
                    'place' => 'Germany',
                    'west_bound_longitude' => 5.87,
                    'east_bound_longitude' => 15.04,
                    'south_bound_latitude' => 47.27,
                    'north_bound_latitude' => 55.06,
                ],
            ]
        );

        // 3. Resource with single POLYGON (Lake Constance / Bodensee)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Polygon (Lake Constance)',
            doi: '10.5880/geoloc.poly.001',
            abstract: 'Test resource with a polygon representing Lake Constance (Bodensee). The map should show a filled polygon area.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                [
                    'place' => 'Lake Constance (Bodensee)',
                    'polygon_points' => [
                        ['longitude' => 9.1893, 'latitude' => 47.6631], // Konstanz
                        ['longitude' => 9.3667, 'latitude' => 47.5000], // Romanshorn
                        ['longitude' => 9.6333, 'latitude' => 47.5000], // Bregenz
                        ['longitude' => 9.7000, 'latitude' => 47.5333], // Lindau
                        ['longitude' => 9.5000, 'latitude' => 47.6333], // Friedrichshafen
                        ['longitude' => 9.1893, 'latitude' => 47.6631], // Close polygon at Konstanz
                    ],
                    'in_polygon_point_longitude' => 9.4,
                    'in_polygon_point_latitude' => 47.55,
                ],
            ]
        );

        // 4. Resource with MULTIPLE LOCATIONS (mixed types)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Multiple Locations (Mixed)',
            doi: '10.5880/geoloc.multi.001',
            abstract: 'Test resource with multiple geo locations of different types. The map should auto-zoom to fit all: a point in Berlin, a bounding box in Bavaria, and a polygon in the Alps.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                // Point: Berlin
                [
                    'place' => 'Berlin, Germany',
                    'point_longitude' => 13.4050,
                    'point_latitude' => 52.5200,
                ],
                // Bounding Box: Bavaria
                [
                    'place' => 'Bavaria, Germany',
                    'west_bound_longitude' => 8.97,
                    'east_bound_longitude' => 13.84,
                    'south_bound_latitude' => 47.27,
                    'north_bound_latitude' => 50.56,
                ],
                // Polygon: Simplified Alps region
                [
                    'place' => 'Alps Region',
                    'polygon_points' => [
                        ['longitude' => 10.0, 'latitude' => 47.5],
                        ['longitude' => 12.0, 'latitude' => 47.0],
                        ['longitude' => 14.0, 'latitude' => 47.5],
                        ['longitude' => 12.0, 'latitude' => 48.0],
                        ['longitude' => 10.0, 'latitude' => 47.5], // Close polygon
                    ],
                    'in_polygon_point_longitude' => 12.0,
                    'in_polygon_point_latitude' => 47.5,
                ],
            ]
        );

        // 5. Resource with MULTIPLE POINTS (seismic stations)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Multiple Points (Seismic Stations)',
            doi: '10.5880/geoloc.points.001',
            abstract: 'Test resource with multiple point locations representing fictional seismic monitoring stations across Germany.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                ['place' => 'Station GFZ01 - Potsdam', 'point_longitude' => 13.0661, 'point_latitude' => 52.3806],
                ['place' => 'Station GFZ02 - Munich', 'point_longitude' => 11.5820, 'point_latitude' => 48.1351],
                ['place' => 'Station GFZ03 - Hamburg', 'point_longitude' => 9.9937, 'point_latitude' => 53.5511],
                ['place' => 'Station GFZ04 - Cologne', 'point_longitude' => 6.9603, 'point_latitude' => 50.9375],
                ['place' => 'Station GFZ05 - Frankfurt', 'point_longitude' => 8.6821, 'point_latitude' => 50.1109],
            ]
        );

        // 6. Resource with NO GEO LOCATIONS (control case - map should NOT appear)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: No Locations (Control)',
            doi: '10.5880/geoloc.none.001',
            abstract: 'Test resource WITHOUT any geo locations. The Location section should NOT be visible on the landing page.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: []
        );

        // 7. Resource with ONLY PLACE NAME (no coordinates - map should NOT appear)
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Place Only (No Coordinates)',
            doi: '10.5880/geoloc.place.001',
            abstract: 'Test resource with only a place name but no coordinates. The Location section should NOT be visible since there is nothing to display on the map.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                [
                    'place' => 'North Atlantic Ocean',
                    // No coordinates - only place name
                ],
            ]
        );

        // 8. Resource with GLOBAL BOUNDING BOX
        $this->createResourceWithGeoLocations(
            title: 'GeoLocation Test: Global Coverage',
            doi: '10.5880/geoloc.global.001',
            abstract: 'Test resource with a bounding box covering the entire globe. Tests how the map handles very large areas.',
            resourceType: $resourceType,
            language: $language,
            titleType: $titleType,
            abstractType: $abstractType,
            testPerson: $testPerson,
            geoLocations: [
                [
                    'place' => 'Global',
                    'west_bound_longitude' => -180.0,
                    'east_bound_longitude' => 180.0,
                    'south_bound_latitude' => -90.0,
                    'north_bound_latitude' => 90.0,
                ],
            ]
        );

        $this->command->info('✓ Created 8 GeoLocation test resources with published landing pages.');
        $this->command->info('');
        $this->command->info('Access them at:');
        $this->command->newLine();

        // List created resources with dynamically generated URLs
        $resources = Resource::where('doi', 'like', '10.5880/geoloc.%')->get();
        foreach ($resources as $resource) {
            $url = route('landing-page.show', ['resource' => $resource->id]);
            $this->command->info("  - {$url}");
        }
    }

    /**
     * Create a resource with geo locations and a published landing page.
     * If the resource already exists, updates its geo_locations to ensure consistency.
     *
     * @param  array<int, array<string, mixed>>  $geoLocations
     */
    private function createResourceWithGeoLocations(
        string $title,
        string $doi,
        string $abstract,
        ResourceType $resourceType,
        Language $language,
        TitleType $titleType,
        DescriptionType $abstractType,
        Person $testPerson,
        array $geoLocations
    ): Resource {
        // Check if resource already exists
        $existing = Resource::where('doi', $doi)->first();
        if ($existing) {
            // Update geo_locations for existing resource to ensure they are present
            $this->syncGeoLocations($existing, $geoLocations);
            $this->command->info("  ↻ Updated existing resource: {$doi}");

            return $existing;
        }

        // Create resource
        $resource = Resource::create([
            'doi' => $doi,
            'identifier_type' => 'DOI',
            'publication_year' => 2024,
            'resource_type_id' => $resourceType->id,
            'language_id' => $language->id,
            'version' => '1.0',
        ]);

        // Create title
        Title::create([
            'resource_id' => $resource->id,
            'value' => $title,
            'title_type_id' => $titleType->id,
        ]);

        // Create abstract
        Description::create([
            'resource_id' => $resource->id,
            'value' => $abstract,
            'description_type_id' => $abstractType->id,
        ]);

        // Create creator
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $testPerson->id,
            'position' => 1,
        ]);

        // Create geo locations
        $this->syncGeoLocations($resource, $geoLocations);

        // Create published landing page
        LandingPage::create([
            'resource_id' => $resource->id,
            'slug' => 'geoloc-test-'.str_replace(['10.5880/geoloc.', '.001'], '', $doi),
            'template' => 'default_gfz',
            'is_published' => true,
            'published_at' => now(),
            'preview_token' => bin2hex(random_bytes(32)),
        ]);

        $this->command->info("  ✓ Created: {$title}");

        return $resource;
    }

    /**
     * Sync geo locations for a resource.
     * Deletes existing geo_locations and recreates them to ensure consistency.
     *
     * @param  array<int, array<string, mixed>>  $geoLocations
     */
    private function syncGeoLocations(Resource $resource, array $geoLocations): void
    {
        // Delete existing geo_locations for this resource
        GeoLocation::where('resource_id', $resource->id)->delete();

        // Create new geo_locations
        foreach ($geoLocations as $geoData) {
            GeoLocation::create([
                'resource_id' => $resource->id,
                'place' => $geoData['place'] ?? null,
                'point_longitude' => $geoData['point_longitude'] ?? null,
                'point_latitude' => $geoData['point_latitude'] ?? null,
                'west_bound_longitude' => $geoData['west_bound_longitude'] ?? null,
                'east_bound_longitude' => $geoData['east_bound_longitude'] ?? null,
                'south_bound_latitude' => $geoData['south_bound_latitude'] ?? null,
                'north_bound_latitude' => $geoData['north_bound_latitude'] ?? null,
                'polygon_points' => $geoData['polygon_points'] ?? null,
                'in_polygon_point_longitude' => $geoData['in_polygon_point_longitude'] ?? null,
                'in_polygon_point_latitude' => $geoData['in_polygon_point_latitude'] ?? null,
            ]);
        }
    }
}
