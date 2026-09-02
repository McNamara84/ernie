<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\DescriptionType;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
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

it('publishes separate DOI and IGSN portal endpoint families', function () {
    expect(route('portal.doi', absolute: false))->toBe('/doi-search')
        ->and(route('portal.doi.count', absolute: false))->toBe('/doi-search/count')
        ->and(route('portal.doi.free-keyword-suggestions', absolute: false))->toBe('/doi-search/free-keyword-suggestions')
        ->and(route('portal.doi.map', absolute: false))->toBe('/doi-search/map')
        ->and(route('portal.doi.search-analytics', absolute: false))->toBe('/doi-search/search-analytics')
        ->and(route('portal.igsn', absolute: false))->toBe('/igsn-search')
        ->and(route('portal.igsn.count', absolute: false))->toBe('/igsn-search/count')
        ->and(route('portal.igsn.free-keyword-suggestions', absolute: false))->toBe('/igsn-search/free-keyword-suggestions')
        ->and(route('portal.igsn.map', absolute: false))->toBe('/igsn-search/map')
        ->and(route('portal.igsn.search-analytics', absolute: false))->toBe('/igsn-search/search-analytics');

    foreach (['/portal', '/search', '/search/count', '/search/free-keyword-suggestions', '/search/map', '/search/search-analytics'] as $oldPath) {
        $this->get($oldPath)->assertNotFound();
    }
});

describe('Portal Page Display', function () {
    it('displays the portal page', function () {
        $response = $this->get(route('portal.doi'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('portal')
            ->has('resources')
            ->missing('mapData')
            ->has('pagination')
            ->has('filters')
        );
    });

    it('is accessible without authentication', function () {
        // Portal should be a public page
        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('portal'));
    });

    it('strongly throttles known ai bots without throttling normal visitors on the same ip', function () {
        config([
            'bot_protection.enabled' => true,
            'bot_protection.ai_user_agents' => ['GPTBot'],
            'bot_protection.limits.ai_bot_public_per_minute' => 1,
            'bot_protection.limits.public_portal_per_minute' => 10,
        ]);

        RateLimiter::clear('portal:ai-bot:203.0.113.10');
        RateLimiter::clear('portal:public:203.0.113.10');

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(route('portal.doi'))->assertOk();

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'GPTBot',
        ])->get(route('portal.doi'))->assertTooManyRequests();

        $this->withServerVariables([
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ])->get(route('portal.doi'))->assertOk();
    });

    it('bypasses public discovery limits for marked Playwright requests outside production', function () {
        config([
            'app.env' => 'testing',
            'bot_protection.enabled' => true,
            'bot_protection.limits.public_portal_per_minute' => 1,
        ]);

        RateLimiter::clear('portal:public:203.0.113.11');

        $playwrightRequest = fn () => $this
            ->withHeader('X-ERNIE-Playwright-Test', '1')
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.11',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
            ])
            ->get(route('portal.doi'));

        $playwrightRequest()->assertOk();
        $playwrightRequest()->assertOk();

        config(['app.env' => 'production']);
        RateLimiter::clear('portal:public:203.0.113.12');

        $productionRequest = fn () => $this
            ->withHeader('X-ERNIE-Playwright-Test', '1')
            ->withServerVariables([
                'REMOTE_ADDR' => '203.0.113.12',
                'HTTP_USER_AGENT' => 'Mozilla/5.0',
            ])
            ->get(route('portal.doi'));

        $productionRequest()->assertOk();
        $productionRequest()->assertTooManyRequests();
    });
});

describe('Portal Search', function () {
    it('can search by title', function () {
        $resource = createPublishedResource($this->datasetType, 'Earthquake Data Analysis');
        createPublishedResource($this->datasetType, 'Climate Change Study');

        $this->get(route('portal.doi', ['q' => 'Earthquake']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.query', 'Earthquake')
                ->has('resources', 1)
                ->where('pagination.count_status', 'pending')
            );

        $this->getJson(route('portal.doi.count', ['q' => 'Earthquake']))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('last_page', 1);
    });

    it('can search by DOI', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');
        $resource->update(['doi' => '10.5880/test.2024.001']);

        $this->get(route('portal.doi', ['q' => '10.5880/test']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
            );

        $this->getJson(route('portal.doi.count', ['q' => '10.5880/test']))
            ->assertOk()
            ->assertJsonPath('total', 1);
    });

    it('returns empty results for non-matching search', function () {
        createPublishedResource($this->datasetType, 'Real Dataset');

        $this->get(route('portal.doi', ['q' => 'NonExistentTerm12345']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 0)
            );

        $this->getJson(route('portal.doi.count', ['q' => 'NonExistentTerm12345']))
            ->assertOk()
            ->assertJsonPath('total', 0);
    });
});

describe('Portal Type Filter', function () {
    it('shows only non-physical resources in the DOI portal by default', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', [])
                ->where('filters.exclude_type', null)
                ->where('portal.kind', 'doi')
                ->where('portal.title', 'Data Portal')
                ->where('portal.showResourceTypeFilter', true)
                ->has('resources', 1)
                ->where('resources.0.isIgsn', false)
                ->where('resourceTypeFacets', fn ($facets) => collect($facets)->doesntContain('slug', 'physical-object'))
            );

        $this->getJson(route('portal.doi.count'))
            ->assertOk()
            ->assertJsonPath('total', 1);
    });

    it('can filter by dataset type only', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal.doi', ['type' => 'dataset']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', ['dataset'])
                ->has('resources', 1)
            );

        $this->getJson(route('portal.doi.count', ['type' => 'dataset']))
            ->assertOk()
            ->assertJsonPath('total', 1);
    });

    it('does not allow a physical-object query parameter to escape the DOI scope', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal.doi', ['type' => 'physical-object']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', [])
                ->has('resources', 1)
                ->where('resources.0.isIgsn', false)
            );

        $this->getJson(route('portal.doi.count', ['type' => 'physical-object']))
            ->assertOk()
            ->assertJsonPath('total', 1);
    });

    it('shows only physical samples in the IGSN portal and ignores type filters', function () {
        createPublishedResource($this->datasetType, 'Dataset 1');
        createPublishedResource($this->physicalObjectType, 'IGSN Sample 1');

        $this->get(route('portal.igsn', ['type' => ['dataset']]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.type', [])
                ->where('filters.exclude_type', null)
                ->where('portal.kind', 'igsn')
                ->where('portal.title', 'IGSN Portal')
                ->where('portal.showResourceTypeFilter', false)
                ->where('resourceTypeFacets', [])
                ->has('resources', 1)
                ->where('resources.0.isIgsn', true)
            );

        $doiCount = $this->getJson(route('portal.doi.count', ['type' => ['dataset']]))
            ->assertOk()
            ->assertJsonPath('total', 1);
        $igsnCount = $this->getJson(route('portal.igsn.count', ['type' => ['dataset']]))
            ->assertOk()
            ->assertJsonPath('total', 1);

        expect($doiCount->json('filter_fingerprint'))->not->toBe($igsnCount->json('filter_fingerprint'));
    });

    it('scopes datacenter facets and counts to each portal', function () {
        $doiDatacenter = Datacenter::create(['name' => 'DOI Datacenter']);
        $igsnDatacenter = Datacenter::create(['name' => 'IGSN Datacenter']);
        createPublishedResource($this->datasetType, 'Dataset')->update(['datacenter_id' => $doiDatacenter->id]);
        createPublishedResource($this->physicalObjectType, 'Sample')->update(['datacenter_id' => $igsnDatacenter->id]);

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('datacenterFacets', 1)
                ->where('datacenterFacets.0.name', 'DOI Datacenter')
                ->where('datacenterFacets.0.count', 1)
            );

        $this->get(route('portal.igsn'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('datacenterFacets', 1)
                ->where('datacenterFacets.0.name', 'IGSN Datacenter')
                ->where('datacenterFacets.0.count', 1)
            );
    });
});

describe('Portal Pagination', function () {
    it('paginates results', function () {
        // Create 25 resources (more than default 20 per page)
        for ($i = 1; $i <= 25; $i++) {
            createPublishedResource($this->datasetType, "Dataset {$i}");
        }

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 20)
                ->where('pagination.per_page', 20)
                ->where('pagination.current_page', 1)
                ->where('pagination.last_page', null)
                ->where('pagination.total', null)
                ->where('pagination.has_more', true)
            );

        $this->getJson(route('portal.doi.count'))
            ->assertOk()
            ->assertJsonPath('total', 25)
            ->assertJsonPath('last_page', 2);
    });

    it('can navigate to second page', function () {
        for ($i = 1; $i <= 15; $i++) {
            createPublishedResource($this->datasetType, "Dataset {$i}");
        }

        $this->get(route('portal.doi', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('pagination.current_page', 2)
            );
    });
});

describe('Portal Map Bootstrap', function () {
    it('does not embed unbounded map data in the initial page response', function () {
        $resource = createPublishedResource($this->datasetType, 'Geo Dataset');
        GeoLocation::factory()->withPoint(13.4, 52.5)->create(['resource_id' => $resource->id]);

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
                ->missing('mapData')
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

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
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

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
            );
    });
});

describe('Portal Resource Transformation', function () {
    it('includes correct resource data', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');
        $resource->update(['doi' => '10.5880/test.2024.001', 'publication_year' => 2024]);

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('resources', 1)
                ->where('resources.0.title', 'Test Dataset')
                ->where('resources.0.doi', '10.5880/test.2024.001')
                ->where('resources.0.abstract', null)
                ->where('resources.0.year', 2024)
                ->where('resources.0.resourceType', 'Dataset')
                ->where('resources.0.isIgsn', false)
            );
    });

    it('includes the abstract description when present', function () {
        $resource = createPublishedResource($this->datasetType, 'Test Dataset');
        $abstractType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract']
        );

        $resource->descriptions()->create([
            'description_type_id' => $abstractType->id,
            'value' => 'A concise abstract for portal preview testing.',
        ]);

        GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => 52.5,
            'point_longitude' => 13.4,
        ]);

        $this->get(route('portal.doi'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('resources.0.abstract', 'A concise abstract for portal preview testing.')
            );
    });

    it('correctly identifies IGSN resources', function () {
        createPublishedResource($this->physicalObjectType, 'Sample');

        $this->get(route('portal.igsn'))
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

        $this->get(route('portal.doi'))
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
