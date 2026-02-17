<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\PortalSearchService;

beforeEach(function () {
    $this->service = new PortalSearchService;

    $this->resourceType = ResourceType::create(['name' => 'Dataset', 'slug' => 'dataset']);
    $this->igsnType = ResourceType::create(['name' => 'PhysicalObject', 'slug' => 'physical-object']);
    $this->titleType = TitleType::create(['name' => 'Main Title', 'slug' => 'main-title']);
});

function createPublishedResource(array $overrides = []): Resource
{
    $resource = Resource::factory()->create(array_merge([
        'resource_type_id' => test()->resourceType->id,
    ], $overrides));

    Title::create([
        'resource_id' => $resource->id,
        'value' => $overrides['title'] ?? 'Test Resource',
        'title_type_id' => test()->titleType->id,
    ]);

    LandingPage::create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    return $resource->fresh();
}

describe('search', function () {
    test('returns only published resources', function () {
        createPublishedResource();

        $unpublished = Resource::factory()->create(['resource_type_id' => $this->resourceType->id]);
        Title::create([
            'resource_id' => $unpublished->id,
            'value' => 'Unpublished',
            'title_type_id' => $this->titleType->id,
        ]);
        LandingPage::create([
            'resource_id' => $unpublished->id,
            'is_published' => false,
        ]);

        $results = $this->service->search();

        expect($results->total())->toBe(1);
    });

    test('paginates results with default per page', function () {
        for ($i = 0; $i < 25; $i++) {
            createPublishedResource(['title' => "Resource $i"]);
        }

        $results = $this->service->search();

        expect($results->perPage())->toBe(20)
            ->and($results->total())->toBe(25);
    });

    test('respects custom per page up to max', function () {
        for ($i = 0; $i < 10; $i++) {
            createPublishedResource();
        }

        $results = $this->service->search(['per_page' => 5]);
        expect($results->perPage())->toBe(5);

        $results = $this->service->search(['per_page' => 100]);
        expect($results->perPage())->toBe(50);
    });
});

describe('type filter', function () {
    test('filters by doi type', function () {
        createPublishedResource();
        createPublishedResource(['resource_type_id' => $this->igsnType->id, 'title' => 'IGSN Resource']);

        $results = $this->service->search(['type' => 'doi']);

        expect($results->total())->toBe(1);
    });

    test('filters by igsn type', function () {
        createPublishedResource();
        createPublishedResource(['resource_type_id' => $this->igsnType->id, 'title' => 'IGSN Resource']);

        $results = $this->service->search(['type' => 'igsn']);

        expect($results->total())->toBe(1);
    });

    test('returns all types when filter is all', function () {
        createPublishedResource();
        createPublishedResource(['resource_type_id' => $this->igsnType->id, 'title' => 'IGSN Resource']);

        $results = $this->service->search(['type' => 'all']);

        expect($results->total())->toBe(2);
    });
});

describe('search query', function () {
    test('searches by doi', function () {
        createPublishedResource(['doi' => '10.5880/test.2024.001']);
        createPublishedResource(['doi' => '10.5880/other.2024.002', 'title' => 'Other']);

        $results = $this->service->search(['query' => 'test.2024']);

        expect($results->total())->toBe(1);
    });

    test('searches by title', function () {
        createPublishedResource(['title' => 'Seismic Data Analysis']);
        createPublishedResource(['title' => 'Climate Change Report']);

        $results = $this->service->search(['query' => 'Seismic']);

        expect($results->total())->toBe(1);
    });

    test('searches by creator family name', function () {
        $resource = createPublishedResource();
        $person = Person::create([
            'given_name' => 'Albert',
            'family_name' => 'Einstein',
        ]);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        createPublishedResource(['title' => 'Other']);

        $results = $this->service->search(['query' => 'Einstein']);

        expect($results->total())->toBe(1);
    });

    test('searches by institution name', function () {
        $resource = createPublishedResource();
        $institution = Institution::create(['name' => 'GFZ Helmholtz Centre']);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'position' => 0,
        ]);

        createPublishedResource(['title' => 'Other']);

        $results = $this->service->search(['query' => 'Helmholtz']);

        expect($results->total())->toBe(1);
    });

    test('ignores empty search query', function () {
        createPublishedResource();
        createPublishedResource(['title' => 'Another']);

        $results = $this->service->search(['query' => '']);
        expect($results->total())->toBe(2);

        $results = $this->service->search(['query' => '   ']);
        expect($results->total())->toBe(2);
    });
});

describe('getMapData', function () {
    test('returns only resources with geo locations', function () {
        $withGeo = createPublishedResource(['title' => 'With Geo']);
        GeoLocation::create([
            'resource_id' => $withGeo->id,
            'point_latitude' => 52.38,
            'point_longitude' => 13.06,
        ]);

        createPublishedResource(['title' => 'Without Geo']);

        $mapData = $this->service->getMapData();

        expect($mapData)->toHaveCount(1)
            ->and($mapData->first()->id)->toBe($withGeo->id);
    });
});

describe('transformForPortal', function () {
    test('transforms resource with all fields', function () {
        $resource = createPublishedResource([
            'doi' => '10.5880/test.2024.001',
            'publication_year' => 2024,
        ]);

        $person = Person::create(['given_name' => 'Jane', 'family_name' => 'Doe']);
        ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $transformed = $this->service->transformForPortal($resource);

        expect($transformed['id'])->toBe($resource->id)
            ->and($transformed['doi'])->toBe('10.5880/test.2024.001')
            ->and($transformed['title'])->toBe('Test Resource')
            ->and($transformed['year'])->toBe(2024)
            ->and($transformed['resourceType'])->toBe('Dataset')
            ->and($transformed['isIgsn'])->toBeFalse()
            ->and($transformed['creators'])->toHaveCount(1)
            ->and($transformed['creators'][0]['name'])->toBe('Doe')
            ->and($transformed['creators'][0]['givenName'])->toBe('Jane');
    });

    test('returns Untitled when no title exists', function () {
        $resource = Resource::factory()->create(['resource_type_id' => $this->resourceType->id]);
        LandingPage::create(['resource_id' => $resource->id, 'is_published' => true, 'published_at' => now()]);

        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $transformed = $this->service->transformForPortal($resource);

        expect($transformed['title'])->toBe('Untitled');
    });

    test('identifies IGSN resources', function () {
        $resource = createPublishedResource(['resource_type_id' => $this->igsnType->id, 'title' => 'IGSN Sample']);
        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $transformed = $this->service->transformForPortal($resource);

        expect($transformed['isIgsn'])->toBeTrue()
            ->and($transformed['resourceTypeSlug'])->toBe('physical-object');
    });
});
