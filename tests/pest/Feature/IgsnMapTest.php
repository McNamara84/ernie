<?php

use App\Models\GeoLocation;
use App\Models\IgsnMetadata;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\TitleType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Seed required data
    $this->artisan('db:seed', ['--class' => 'TitleTypeSeeder']);
    $this->artisan('db:seed', ['--class' => 'ResourceTypeSeeder']);
});

describe('IGSN Map Page', function () {
    it('requires authentication', function () {
        $response = $this->get('/igsns-map');

        $response->assertRedirect('/login');
    });

    it('returns successful response for authenticated users', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/igsns-map');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('igsns/map'));
    });

    it('returns only IGSNs with point coordinates', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        // Create IGSN with coordinates (should be included)
        $igsnWithCoords = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-WITH-COORDS',
            'publication_year' => 2026,
        ]);
        $igsnWithCoords->titles()->create([
            'value' => 'Sample With Coordinates',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);
        IgsnMetadata::create([
            'resource_id' => $igsnWithCoords->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);
        GeoLocation::factory()->withPoint(13.0, 52.0)->create([
            'resource_id' => $igsnWithCoords->id,
        ]);

        // Create IGSN without coordinates (should be excluded)
        $igsnWithoutCoords = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-WITHOUT-COORDS',
            'publication_year' => 2026,
        ]);
        $igsnWithoutCoords->titles()->create([
            'value' => 'Sample Without Coordinates',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);
        IgsnMetadata::create([
            'resource_id' => $igsnWithoutCoords->id,
            'sample_type' => 'Sediment',
            'upload_status' => 'pending',
        ]);
        // GeoLocation with only place name, no coordinates
        GeoLocation::factory()->create([
            'resource_id' => $igsnWithoutCoords->id,
            'place' => 'Berlin, Germany',
        ]);

        // Create regular dataset (should be excluded)
        $datasetType = ResourceType::where('slug', 'dataset')->first();
        $dataset = Resource::factory()->create([
            'resource_type_id' => $datasetType->id,
            'doi' => '10.5880/test.2026.001',
            'publication_year' => 2026,
        ]);
        GeoLocation::factory()->withPoint(10.0, 50.0)->create([
            'resource_id' => $dataset->id,
        ]);

        $response = $this->actingAs($user)->get('/igsns-map');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/map')
            ->has('igsns', 1) // Only the IGSN with coordinates
            ->where('igsns.0.igsn', 'IGSN-WITH-COORDS')
            ->where('igsns.0.title', 'Sample With Coordinates')
        );
    });

    it('includes correct data structure with geolocations', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-TEST-001',
            'publication_year' => 2025,
        ]);

        $resource->titles()->create([
            'value' => 'Test Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);

        $person = Person::factory()->create([
            'family_name' => 'Doe',
            'given_name' => 'John',
        ]);

        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Core',
            'upload_status' => 'validated',
        ]);

        GeoLocation::factory()->withPoint(13.4050, 52.5200)->create([
            'resource_id' => $resource->id,
            'place' => 'Berlin',
        ]);

        $response = $this->actingAs($user)->get('/igsns-map');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/map')
            ->has('igsns', 1)
            ->has('igsns.0', fn ($igsn) => $igsn
                ->where('id', $resource->id)
                ->where('igsn', 'IGSN-TEST-001')
                ->where('title', 'Test Sample')
                ->where('creator', 'John Doe')
                ->where('publication_year', 2025)
                ->has('geoLocations', 1)
                ->has('geoLocations.0', fn ($geo) => $geo
                    ->hasAll(['id', 'latitude', 'longitude', 'place'])
                    ->where('latitude', 52.52)
                    ->where('longitude', 13.405)
                    ->where('place', 'Berlin')
                )
            )
        );
    });

    it('returns empty array when no IGSNs have coordinates', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        // Create IGSN without geolocation
        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-NO-GEO',
            'publication_year' => 2026,
        ]);
        $resource->titles()->create([
            'value' => 'Sample Without Geo',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Rock',
            'upload_status' => 'pending',
        ]);

        $response = $this->actingAs($user)->get('/igsns-map');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/map')
            ->has('igsns', 0)
        );
    });

    it('includes multiple geolocations for a single IGSN', function () {
        $user = User::factory()->create();
        $physicalObjectType = ResourceType::where('slug', 'physical-object')->first();
        $mainTitleType = TitleType::where('slug', 'MainTitle')->first();

        $resource = Resource::factory()->create([
            'resource_type_id' => $physicalObjectType->id,
            'doi' => 'IGSN-MULTI-GEO',
            'publication_year' => 2026,
        ]);
        $resource->titles()->create([
            'value' => 'Multi-Location Sample',
            'title_type_id' => $mainTitleType->id,
            'position' => 1,
        ]);
        IgsnMetadata::create([
            'resource_id' => $resource->id,
            'sample_type' => 'Sediment',
            'upload_status' => 'pending',
        ]);

        // Create multiple geolocations
        GeoLocation::factory()->withPoint(10.0, 50.0)->create([
            'resource_id' => $resource->id,
            'place' => 'Location A',
        ]);
        GeoLocation::factory()->withPoint(11.0, 51.0)->create([
            'resource_id' => $resource->id,
            'place' => 'Location B',
        ]);

        $response = $this->actingAs($user)->get('/igsns-map');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('igsns/map')
            ->has('igsns', 1)
            ->has('igsns.0.geoLocations', 2)
        );
    });
});
