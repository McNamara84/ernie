<?php

declare(strict_types=1);

use App\Models\Description;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Subject;
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

    it('finds resources by subject value', function () {
        $resource = createPublishedResourceForSearch('Seismic Paper', $this->titleType);
        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Volcanology',
        ]);

        // Resource without matching subject
        createPublishedResourceForSearch('Other Paper', $this->titleType);

        $results = $this->service->search(['query' => 'Volcanology']);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($resource->id);
    });

    it('finds resources by partial subject value', function () {
        $resource = createPublishedResourceForSearch('Test Paper', $this->titleType);
        Subject::factory()->gcmd()->create([
            'resource_id' => $resource->id,
            'value' => 'EARTH SCIENCE > ATMOSPHERE > ATMOSPHERIC CHEMISTRY',
        ]);

        $results = $this->service->search(['query' => 'ATMOSPHERIC']);

        expect($results->total())->toBe(1);
    });

    it('finds resources by MSL keyword value', function () {
        $resource = createPublishedResourceForSearch('Rock Paper', $this->titleType);
        Subject::factory()->msl()->create([
            'resource_id' => $resource->id,
            'value' => 'Geochemistry',
        ]);

        $results = $this->service->search(['query' => 'Geochemistry']);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($resource->id);
    });
});

// =========================================================================
// keyword filtering
// =========================================================================

describe('keyword filtering', function () {
    it('filters resources by a single keyword', function () {
        $matching = createPublishedResourceForSearch('Matching Paper', $this->titleType);
        Subject::factory()->create([
            'resource_id' => $matching->id,
            'value' => 'Seismology',
        ]);

        $nonMatching = createPublishedResourceForSearch('Other Paper', $this->titleType);
        Subject::factory()->create([
            'resource_id' => $nonMatching->id,
            'value' => 'Geology',
        ]);

        $results = $this->service->search(['keywords' => ['Seismology']]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($matching->id);
    });

    it('applies AND logic for multiple keywords', function () {
        // Resource with both keywords
        $both = createPublishedResourceForSearch('Both Keywords', $this->titleType);
        Subject::factory()->create(['resource_id' => $both->id, 'value' => 'Seismology']);
        Subject::factory()->create(['resource_id' => $both->id, 'value' => 'Geology']);

        // Resource with only one keyword
        $oneOnly = createPublishedResourceForSearch('One Keyword', $this->titleType);
        Subject::factory()->create(['resource_id' => $oneOnly->id, 'value' => 'Seismology']);

        $results = $this->service->search(['keywords' => ['Seismology', 'Geology']]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($both->id);
    });

    it('returns all resources when keywords is empty', function () {
        createPublishedResourceForSearch('Paper A', $this->titleType);
        createPublishedResourceForSearch('Paper B', $this->titleType);

        $results = $this->service->search(['keywords' => []]);

        expect($results->total())->toBe(2);
    });

    it('returns all resources when keywords is null', function () {
        createPublishedResourceForSearch('Paper A', $this->titleType);
        createPublishedResourceForSearch('Paper B', $this->titleType);

        $results = $this->service->search(['keywords' => null]);

        expect($results->total())->toBe(2);
    });

    it('ignores empty string keywords', function () {
        createPublishedResourceForSearch('Paper A', $this->titleType);
        createPublishedResourceForSearch('Paper B', $this->titleType);

        $results = $this->service->search(['keywords' => ['', '  ']]);

        expect($results->total())->toBe(2);
    });

    it('combines keyword filter with text search', function () {
        // Resource matching both query and keyword
        $matching = createPublishedResourceForSearch('Seismic Activity', $this->titleType);
        Subject::factory()->create(['resource_id' => $matching->id, 'value' => 'Volcanology']);

        // Resource matching only query
        createPublishedResourceForSearch('Seismic Data', $this->titleType);

        // Resource matching only keyword
        $keywordOnly = createPublishedResourceForSearch('Other Paper', $this->titleType);
        Subject::factory()->create(['resource_id' => $keywordOnly->id, 'value' => 'Volcanology']);

        $results = $this->service->search([
            'query' => 'Seismic',
            'keywords' => ['Volcanology'],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($matching->id);
    });
});

// =========================================================================
// Type filtering
// =========================================================================

describe('type filtering', function () {
    it('returns all types when filter is empty array', function () {
        $datasetType = ResourceType::factory()->create([
            'name' => 'Dataset', 'slug' => 'dataset',
        ]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType, $datasetType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => []]);

        expect($results->total())->toBe(2);
    });

    it('filters by PhysicalObject slug', function () {
        $datasetType = ResourceType::factory()->create([
            'name' => 'Dataset', 'slug' => 'dataset',
        ]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType, $datasetType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => ['physical-object']]);

        expect($results->total())->toBe(1);
    });

    it('filters by Dataset slug', function () {
        $datasetType = ResourceType::factory()->create([
            'name' => 'Dataset', 'slug' => 'dataset',
        ]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('DOI Paper', $this->titleType, $datasetType);
        createPublishedResourceForSearch('IGSN Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => ['dataset']]);

        expect($results->total())->toBe(1);
    });

    it('filters by multiple slugs', function () {
        $datasetType = ResourceType::factory()->create([
            'name' => 'Dataset', 'slug' => 'dataset',
        ]);
        $softwareType = ResourceType::factory()->create([
            'name' => 'Software', 'slug' => 'software',
        ]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'PhysicalObject', 'slug' => 'physical-object',
        ]);

        createPublishedResourceForSearch('Paper', $this->titleType, $datasetType);
        createPublishedResourceForSearch('App', $this->titleType, $softwareType);
        createPublishedResourceForSearch('Sample', $this->titleType, $physicalObjectType);

        $results = $this->service->search(['type' => ['dataset', 'software']]);

        expect($results->total())->toBe(2);
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

    it('does NOT apply bounds filter to map data', function () {
        // Two resources with different geo locations
        $insideBounds = createPublishedResourceForSearch('Inside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $insideBounds->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        $outsideBounds = createPublishedResourceForSearch('Outside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $outsideBounds->id,
            'point_latitude' => 10.0,
            'point_longitude' => -50.0,
        ]);

        // Map data should return BOTH resources (bounds are not applied)
        $results = $this->service->getMapData([
            'bounds' => ['north' => 54, 'south' => 50, 'east' => 15, 'west' => 11],
        ]);

        expect($results)->toHaveCount(2);
    });
});

// =========================================================================
// Spatial bounds filtering
// =========================================================================

describe('bounds filtering', function () {
    it('filters resources by point within bounding box', function () {
        $inside = createPublishedResourceForSearch('Inside Berlin', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $inside->id,
            'point_latitude' => 52.52,
            'point_longitude' => 13.405,
        ]);

        $outside = createPublishedResourceForSearch('Outside Rio', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $outside->id,
            'point_latitude' => -22.9,
            'point_longitude' => -43.2,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($inside->id);
    });

    it('filters resources by bounding box overlap', function () {
        $overlapping = createPublishedResourceForSearch('Overlapping', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 12.0, east: 14.0, south: 51.0, north: 53.0
        )->create([
            'resource_id' => $overlapping->id,
        ]);

        $nonOverlapping = createPublishedResourceForSearch('Non-overlapping', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: -50.0, east: -40.0, south: -30.0, north: -20.0
        )->create([
            'resource_id' => $nonOverlapping->id,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($overlapping->id);
    });

    it('returns all resources when bounds is null', function () {
        $resource1 = createPublishedResourceForSearch('Paper A', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resource1->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        $resource2 = createPublishedResourceForSearch('Paper B', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resource2->id,
            'point_latitude' => -22.9,
            'point_longitude' => -43.2,
        ]);

        $results = $this->service->search(['bounds' => null]);

        expect($results->total())->toBe(2);
    });

    it('includes resources with partially overlapping bounding boxes', function () {
        // Resource bbox that partially overlaps with search bounds
        $partial = createPublishedResourceForSearch('Partial Overlap', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 10.0, east: 12.5, south: 49.0, north: 51.0
        )->create([
            'resource_id' => $partial->id,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1);
    });

    it('excludes resources with no geo locations when bounds filter is active', function () {
        // Resource WITH geo but outside bounds
        $withGeo = createPublishedResourceForSearch('Has Geo', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $withGeo->id,
            'point_latitude' => -50.0,
            'point_longitude' => -50.0,
        ]);

        // Resource WITHOUT any geo
        createPublishedResourceForSearch('No Geo', $this->titleType);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(0);
    });

    it('handles anti-meridian crossing for points', function () {
        // Point in the Pacific (east of anti-meridian)
        $pacific = createPublishedResourceForSearch('Pacific Island', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $pacific->id,
            'point_latitude' => 0.0,
            'point_longitude' => 175.0,
        ]);

        // Point in Europe (should be excluded)
        $europe = createPublishedResourceForSearch('Europe', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $europe->id,
            'point_latitude' => 52.0,
            'point_longitude' => 13.0,
        ]);

        // Anti-meridian crossing bbox: west=170, east=-170 (wraps around)
        $results = $this->service->search([
            'bounds' => ['north' => 10.0, 'south' => -10.0, 'east' => -170.0, 'west' => 170.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($pacific->id);
    });

    it('handles anti-meridian crossing for bounding boxes', function () {
        // Bounding box in western Pacific (does NOT itself cross anti-meridian)
        $pacific = createPublishedResourceForSearch('Pacific Region', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 172.0, east: 178.0, south: -5.0, north: 5.0
        )->create([
            'resource_id' => $pacific->id,
        ]);

        // Bounding box in Atlantic (should be excluded)
        $atlantic = createPublishedResourceForSearch('Atlantic Region', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: -30.0, east: -20.0, south: 10.0, north: 20.0
        )->create([
            'resource_id' => $atlantic->id,
        ]);

        // Search bbox crossing anti-meridian
        $results = $this->service->search([
            'bounds' => ['north' => 10.0, 'south' => -10.0, 'east' => -165.0, 'west' => 165.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($pacific->id);
    });

    it('handles stored bounding box crossing anti-meridian', function () {
        // Stored bounding box that crosses anti-meridian (west=170, east=-170)
        $crossingBox = createPublishedResourceForSearch('Fiji Region', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 170.0, east: -170.0, south: -20.0, north: -10.0
        )->create([
            'resource_id' => $crossingBox->id,
        ]);

        // Normal box in Europe (should be excluded)
        $europe = createPublishedResourceForSearch('Europe Region', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 10.0, east: 15.0, south: 50.0, north: 55.0
        )->create([
            'resource_id' => $europe->id,
        ]);

        // Search with a normal bbox in the Pacific that overlaps the stored crossing box
        $results = $this->service->search([
            'bounds' => ['north' => -5.0, 'south' => -25.0, 'east' => 175.0, 'west' => 165.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($crossingBox->id);
    });

    it('handles both search and stored box crossing anti-meridian', function () {
        // Stored bounding box that crosses anti-meridian
        $crossingBox = createPublishedResourceForSearch('Pacific Crossing', $this->titleType);
        \App\Models\GeoLocation::factory()->withBox(
            west: 170.0, east: -170.0, south: -10.0, north: 10.0
        )->create([
            'resource_id' => $crossingBox->id,
        ]);

        // Search also crossing anti-meridian
        $results = $this->service->search([
            'bounds' => ['north' => 15.0, 'south' => -15.0, 'east' => -165.0, 'west' => 165.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($crossingBox->id);
    });

    it('combines bounds filter with text search', function () {
        $matchingBoth = createPublishedResourceForSearch('Seismic Berlin Data', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $matchingBoth->id,
            'point_latitude' => 52.52,
            'point_longitude' => 13.405,
        ]);

        $matchingTextOnly = createPublishedResourceForSearch('Seismic Tokyo Data', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $matchingTextOnly->id,
            'point_latitude' => 35.68,
            'point_longitude' => 139.69,
        ]);

        $results = $this->service->search([
            'query' => 'Seismic',
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($matchingBoth->id);
    });

    it('combines bounds filter with keyword filter', function () {
        $matchingBoth = createPublishedResourceForSearch('Paper A', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $matchingBoth->id,
            'point_latitude' => 52.52,
            'point_longitude' => 13.405,
        ]);
        Subject::factory()->create(['resource_id' => $matchingBoth->id, 'value' => 'Seismology']);

        $matchingGeoOnly = createPublishedResourceForSearch('Paper B', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $matchingGeoOnly->id,
            'point_latitude' => 52.0,
            'point_longitude' => 13.0,
        ]);

        $results = $this->service->search([
            'keywords' => ['Seismology'],
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($matchingBoth->id);
    });

    it('matches resource with multiple geo locations if any one intersects', function () {
        $resource = createPublishedResourceForSearch('Multi Geo', $this->titleType);

        // One geo location outside bounds
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => -40.0,
            'point_longitude' => -60.0,
        ]);

        // Another geo location inside bounds
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1);
    });

    it('matches point on boundary edge', function () {
        $resource = createPublishedResourceForSearch('Edge Point', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => 50.0,
            'point_longitude' => 11.0,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1);
    });

    it('matches polygon with only polygon_points via bbox overlap', function () {
        // Polygon without in_polygon_point — vertices span into the search area
        $polygon = createPublishedResourceForSearch('Polygon Only', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $polygon->id,
            'polygon_points' => [
                ['latitude' => 51.0, 'longitude' => 12.0],
                ['latitude' => 53.0, 'longitude' => 12.0],
                ['latitude' => 53.0, 'longitude' => 14.0],
                ['latitude' => 51.0, 'longitude' => 14.0],
                ['latitude' => 51.0, 'longitude' => 12.0],
            ],
            'in_polygon_point_latitude' => null,
            'in_polygon_point_longitude' => null,
        ]);

        // Polygon outside search bounds
        $outside = createPublishedResourceForSearch('Polygon Outside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $outside->id,
            'polygon_points' => [
                ['latitude' => -30.0, 'longitude' => -50.0],
                ['latitude' => -28.0, 'longitude' => -50.0],
                ['latitude' => -28.0, 'longitude' => -48.0],
                ['latitude' => -30.0, 'longitude' => -48.0],
                ['latitude' => -30.0, 'longitude' => -50.0],
            ],
            'in_polygon_point_latitude' => null,
            'in_polygon_point_longitude' => null,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($polygon->id);
    });

    it('matches polygon via bbox overlap even when in_polygon_point is outside bounds', function () {
        // Polygon whose representative point is outside search bounds but
        // whose vertex bounding box still overlaps the search area.
        $polygon = createPublishedResourceForSearch('Polygon InPoint Outside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $polygon->id,
            'polygon_points' => [
                ['latitude' => 49.0, 'longitude' => 12.0],
                ['latitude' => 51.0, 'longitude' => 12.0],
                ['latitude' => 51.0, 'longitude' => 14.0],
                ['latitude' => 49.0, 'longitude' => 14.0],
                ['latitude' => 49.0, 'longitude' => 12.0],
            ],
            // Representative point is south of the search bounds (50-54N)
            'in_polygon_point_latitude' => 49.5,
            'in_polygon_point_longitude' => 13.0,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        // Should match because the polygon's bbox (49-51N) overlaps search (50-54N)
        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($polygon->id);
    });

    it('matches polygon via in_polygon_point when it is within bounds', function () {
        $polygon = createPublishedResourceForSearch('Polygon InPoint Inside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $polygon->id,
            'polygon_points' => [
                ['latitude' => 51.0, 'longitude' => 12.0],
                ['latitude' => 53.0, 'longitude' => 12.0],
                ['latitude' => 53.0, 'longitude' => 14.0],
                ['latitude' => 51.0, 'longitude' => 14.0],
                ['latitude' => 51.0, 'longitude' => 12.0],
            ],
            'in_polygon_point_latitude' => 52.0,
            'in_polygon_point_longitude' => 13.0,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(1)
            ->and($results->items()[0]->id)->toBe($polygon->id);
    });

    it('excludes polygon when both in_polygon_point and bbox are outside bounds', function () {
        $polygon = createPublishedResourceForSearch('Polygon Fully Outside', $this->titleType);
        \App\Models\GeoLocation::factory()->create([
            'resource_id' => $polygon->id,
            'polygon_points' => [
                ['latitude' => -30.0, 'longitude' => -50.0],
                ['latitude' => -28.0, 'longitude' => -50.0],
                ['latitude' => -28.0, 'longitude' => -48.0],
                ['latitude' => -30.0, 'longitude' => -48.0],
                ['latitude' => -30.0, 'longitude' => -50.0],
            ],
            'in_polygon_point_latitude' => -29.0,
            'in_polygon_point_longitude' => -49.0,
        ]);

        $results = $this->service->search([
            'bounds' => ['north' => 54.0, 'south' => 50.0, 'east' => 15.0, 'west' => 11.0],
        ]);

        expect($results->total())->toBe(0);
    });
});
