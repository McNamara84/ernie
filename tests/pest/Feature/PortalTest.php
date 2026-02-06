<?php

declare(strict_types=1);

use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\withoutVite;

uses(RefreshDatabase::class);

beforeEach(function () {
    withoutVite();

    // Create required lookup data
    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->physicalObjectType = ResourceType::factory()->create([
        'name' => 'PhysicalObject',
        'slug' => 'physical-object',
    ]);

    $this->mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title']
    );
});

describe('Portal Page Display', function () {
    it('displays the portal page', function () {
        $response = $this->get(route('portal'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('portal')
            ->has('resources')
            ->has('mapData')
            ->has('pagination')
            ->has('filters')
        );
    });

    it('is accessible without authentication', function () {
        // Portal should be a public page
        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal'));
    });
});

describe('Portal Search', function () {
    it('can search by title', function () {
        $resource = createPublishedResource($this->datasetType, 'Earthquake Data Analysis');
        createPublishedResource($this->datasetType, 'Climate Change Study');

        $this->get(route('portal', ['q' => 'Earthquake']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.query', 'Earthquake')
                ->where('pagination.total', 1)
            );
    });

    it('can search by DOI', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');
        $resource->update(['doi' => '10.5880/test.2024.001']);

        $this->get(route('portal', ['q' => '10.5880/test']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
            );
    });

    it('returns empty results for non-matching search', function () {
        createPublishedResource($this->datasetType, 'Real Dataset');

        $this->get(route('portal', ['q' => 'NonExistentTerm12345']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 0)
            );
    });
});

describe('Portal Type Filter', function () {
    it('shows all resources by default', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'all')
                ->where('pagination.total', 2)
            );
    });

    it('can filter DOI resources only', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal', ['type' => 'doi']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'doi')
                ->where('pagination.total', 1)
            );
    });

    it('can filter IGSN resources only', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal', ['type' => 'igsn']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', 'igsn')
                ->where('pagination.total', 1)
            );
    });
});

describe('Portal Pagination', function () {
    it('paginates results', function () {
        // Create 25 resources (more than default 20 per page)
        for ($i = 1; $i <= 25; $i++) {
            createPublishedResource($this->datasetType, "Dataset {$i}");
        }

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 25)
                ->where('pagination.per_page', 20)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', 2)
            );
    });

    it('can navigate to second page', function () {
        for ($i = 1; $i <= 15; $i++) {
            createPublishedResource($this->datasetType, "Dataset {$i}");
        }

        $this->get(route('portal', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.current_page', 2)
            );
    });
});

describe('Portal Map Data', function () {
    it('includes map data for resources with geo locations', function () {
        $resource = createPublishedResource($this->datasetType, 'Geo Dataset');

        GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('mapData', 1)
                ->where('mapData.0.geoLocations.0.type', 'point')
            );
    });

    it('excludes resources without geo locations from map data', function () {
        // Resource with geo
        $resourceWithGeo = createPublishedResource($this->datasetType, 'Geo Dataset');
        GeoLocation::factory()->create([
            'resource_id' => $resourceWithGeo->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        // Resource without geo
        createPublishedResource($this->datasetType, 'No Geo Dataset');

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 2) // Both in results
                ->has('mapData', 1)   // Only one in map
            );
    });
});

describe('Portal Only Shows Published Resources', function () {
    it('only shows resources with published landing pages', function () {
        // Published resource
        $publishedResource = createPublishedResource($this->datasetType, 'Published Dataset');

        // Unpublished resource (no landing page)
        $unpublishedResource = Resource::factory()->create([
            'resource_type_id' => $this->datasetType->id,
            'publication_year' => 2024,
        ]);
        Title::factory()->create([
            'resource_id' => $unpublishedResource->id,
            'title_type_id' => $this->mainTitleType->id,
            'value' => 'Unpublished Dataset',
        ]);

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
            );
    });

    it('excludes resources with unpublished landing pages', function () {
        // Published
        createPublishedResource($this->datasetType, 'Published');

        // Has landing page but not published
        $draftResource = Resource::factory()->create([
            'resource_type_id' => $this->datasetType->id,
            'publication_year' => 2024,
        ]);
        Title::factory()->create([
            'resource_id' => $draftResource->id,
            'title_type_id' => $this->mainTitleType->id,
            'value' => 'Draft Resource',
        ]);
        LandingPage::factory()->create([
            'resource_id' => $draftResource->id,
            'is_published' => false,
        ]);

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.total', 1)
            );
    });
});

describe('Portal Resource Transformation', function () {
    it('includes correct resource data', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');
        $resource->update(['doi' => '10.5880/test.2024.001', 'publication_year' => 2024]);

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
                ->where('resources.0.title', 'Test Dataset')
                ->where('resources.0.doi', '10.5880/test.2024.001')
                ->where('resources.0.year', 2024)
                ->where('resources.0.resourceType', 'Dataset')
                ->where('resources.0.isIgsn', false)
            );
    });

    it('correctly identifies IGSN resources', function () {
        createPublishedResource($this->physicalObjectType, 'Sample');

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('resources.0.isIgsn', true)
                ->where('resources.0.resourceType', 'PhysicalObject')
            );
    });

    it('includes creator information', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');

        $person = Person::factory()->create([
            'family_name' => 'Smith',
            'given_name' => 'John',
        ]);

        ResourceCreator::factory()->create([
            'resource_id' => $resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 1,
        ]);

        $this->get(route('portal'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources.0.creators', 1)
                ->where('resources.0.creators.0.name', 'Smith')
                ->where('resources.0.creators.0.givenName', 'John')
            );
    });
});

/**
 * Helper function to create a published resource with a landing page.
 */
function createPublishedResource(ResourceType $type, string $title): Resource
{
    $mainTitleType = TitleType::firstOrCreate(
        ['slug' => 'MainTitle'],
        ['name' => 'Main Title']
    );

    $resource = Resource::factory()->create([
        'resource_type_id' => $type->id,
        'publication_year' => 2024,
    ]);

    Title::factory()->create([
        'resource_id' => $resource->id,
        'title_type_id' => $mainTitleType->id,
        'value' => $title,
    ]);

    LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => true,
        'published_at' => now(),
    ]);

    return $resource;
}
