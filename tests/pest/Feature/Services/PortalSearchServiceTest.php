<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\PortalSearchService;

covers(PortalSearchService::class);

beforeEach(function () {
    $this->service = new PortalSearchService;
    $this->titleType = TitleType::factory()->create(['name' => 'Main Title', 'slug' => 'main-title']);
});

/**
 * Create a published resource with a title and landing page.
 */
function createPublishedResourceForSearch(string $title, TitleType $titleType, ?ResourceType $resourceType = null): Resource
{
    $resource = Resource::factory()->create(
        $resourceType !== null ? ['resource_type_id' => $resourceType->id] : []
    );

    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $titleType->id,
        'value' => $title,
    ]);

    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    return $resource;
}

// =========================================================================
// search()
// =========================================================================

describe('search', function () {
    it('returns only resources with published landing pages', function () {
        // Published resource
        createPublishedResourceForSearch('Published Paper', $this->titleType);

        // Unpublished resource
        $unpublished = Resource::factory()->create();
        Title::factory()->create([
            'resource_id' => $unpublished->id,
            'title_type_id' => $this->titleType->id,
            'value' => 'Unpublished Paper',
        ]);
        LandingPage::factory()->create([
            'resource_id' => $unpublished->id,
            'is_published' => false,
        ]);

        $results = $this->service->search();

        expect($results->total())->toBe(1);
    });

    it('respects per_page parameter', function () {
        for ($i = 0; $i < 5; $i++) {
            createPublishedResourceForSearch("Paper {$i}", $this->titleType);
        }

        $results = $this->service->search(['per_page' => 2]);

        expect($results->perPage())->toBe(2)
            ->and($results->total())->toBe(5);
    });

    it('caps per_page at 50', function () {
        createPublishedResourceForSearch('Single Paper', $this->titleType);

        $results = $this->service->search(['per_page' => 100]);

        expect($results->perPage())->toBe(50);
    });

    it('defaults to 20 per page', function () {
        createPublishedResourceForSearch('Paper', $this->titleType);

        $results = $this->service->search();

        expect($results->perPage())->toBe(20);
    });
});

// =========================================================================
// search with query
// =========================================================================

describe('full-text search', function () {
    it('finds resources by DOI', function () {
        $resource = createPublishedResourceForSearch('Test Paper', $this->titleType);
        $doi = $resource->doi;

        $results = $this->service->search(['query' => $doi]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($resource->id);
    });

    it('finds resources by title', function () {
        createPublishedResourceForSearch('Seismic Activity Analysis', $this->titleType);
        createPublishedResourceForSearch('Ocean Temperature Data', $this->titleType);

        $results = $this->service->search(['query' => 'Seismic']);

        expect($results->total())->toBe(1);
    });

    it('returns all resources when query is empty', function () {
        createPublishedResourceForSearch('Paper A', $this->titleType);
        createPublishedResourceForSearch('Paper B', $this->titleType);

        $results = $this->service->search(['query' => '']);

        expect($results->total())->toBe(2);
    });

    it('finds resources by creator family name', function () {
        $resource = createPublishedResourceForSearch('Test Paper', $this->titleType);
        $person = Person::factory()->create(['family_name' => 'Mueller', 'given_name' => 'Hans']);
        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
        ]);

        $results = $this->service->search(['query' => 'Mueller']);

        expect($results->total())->toBe(1);
    });

    it('finds resources by institution name', function () {
        $resource = createPublishedResourceForSearch('Test Paper', $this->titleType);
        $institution = Institution::factory()->create(['name' => 'GFZ Potsdam']);
        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'position' => 0,
        ]);

        $results = $this->service->search(['query' => 'GFZ Potsdam']);

        expect($results->total())->toBe(1);
    });
});

// =========================================================================
// Type filtering
// =========================================================================

describe('type filtering', function () {
    it('returns all types when filter is "all"', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => 'all']);

        expect($results->total())->toBe(2);
    });

    it('filters for IGSNs (PhysicalObject type)', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => 'igsn']);

        expect($results->total())->toBe(1);
    });

    it('filters for DOI (non-PhysicalObject type)', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => 'doi']);

        expect($results->total())->toBe(1);
    });
});

// =========================================================================
// transformForPortal()
// =========================================================================

describe('transformForPortal', function () {
    it('returns expected structure', function () {
        $resource = createPublishedResourceForSearch('My Seismic Data', $this->titleType);
        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $result = $this->service->transformForPortal($resource);

        expect($result)
            ->toBeArray()
            ->toHaveKeys(['id', 'doi', 'title', 'creators', 'year', 'resourceType', 'isIgsn', 'geoLocations', 'landingPageUrl'])
            ->and($result['title'])->toBe('My Seismic Data')
            ->and($result['isIgsn'])->toBeFalse();
    });

    it('returns "Untitled" when no titles exist', function () {
        $resource = Resource::factory()->create();
        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $result = $this->service->transformForPortal($resource);

        expect($result['title'])->toBe('Untitled');
    });

    it('marks PhysicalObject as IGSN', function () {
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        $resource = createPublishedResourceForSearch('Rock Sample', $this->titleType, $physicalObjectType);
        $resource->load(['titles.titleType', 'creators.creatorable', 'resourceType', 'geoLocations', 'landingPage']);

        $result = $this->service->transformForPortal($resource);

        expect($result['isIgsn'])->toBeTrue()
            ->and($result['resourceTypeSlug'])->toBe('physical-object');
    });
});

// =========================================================================
// getMapData()
// =========================================================================

describe('getMapData', function () {
    it('returns only resources with geo locations', function () {
        // Resource with geo location
        $resourceWithGeo = createPublishedResourceForSearch('Geo Paper', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resourceWithGeo->id,
            'point_latitude' => 52.3906,
            'point_longitude' => 13.0645,
        ]);

        // Resource without geo location
        createPublishedResourceForSearch('No Geo Paper', $this->titleType);

        $results = $this->service->getMapData();

        expect($results)->toHaveCount(1)
            ->and($results->first()->id)->toBe($resourceWithGeo->id);
    });
});
