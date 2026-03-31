<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Http\Controllers\PortalController;
use App\Models\GeoLocation;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceType;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

covers(PortalController::class);

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->datasetType = ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]);

    $this->mainTitleType = TitleType::factory()->create([
        'name' => 'MainTitle',
        'slug' => 'main-title',
    ]);

    $this->createPublishedPortalResource = function (string $title = 'Test Dataset', ?string $doi = null): Resource {
        $resource = Resource::factory()->create([
            'resource_type_id' => $this->datasetType->id,
            'doi' => $doi ?? '10.5880/gfz.' . fake()->unique()->numerify('####.###'),
            'publication_year' => 2025,
        ]);

        Title::factory()->create([
            'resource_id' => $resource->id,
            'value' => $title,
            'title_type_id' => $this->mainTitleType->id,
        ]);

        LandingPage::factory()->published()->create([
            'resource_id' => $resource->id,
        ]);

        return $resource;
    };
});

describe('index', function () {
    it('renders the portal page', function () {
        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->component('portal'));
    });

    it('returns empty results when no published resources exist', function () {
        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->has('resources', 0)
                    ->has('pagination')
            );
    });

    it('returns published resources', function () {
        ($this->createPublishedPortalResource)('Seismic Dataset');

        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->has('resources', 1)
            );
    });

    it('excludes unpublished resources', function () {
        // Published resource
        ($this->createPublishedPortalResource)('Published Dataset');

        // Unpublished resource (no landing page)
        $unpublished = Resource::factory()->create([
            'resource_type_id' => $this->datasetType->id,
        ]);
        Title::factory()->create([
            'resource_id' => $unpublished->id,
            'value' => 'Draft Dataset',
            'title_type_id' => $this->mainTitleType->id,
        ]);

        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources', 1));
    });

    it('filters by search query', function () {
        ($this->createPublishedPortalResource)('Seismic Wave Analysis');
        ($this->createPublishedPortalResource)('Climate Data');

        $response = $this->get('/portal?q=Seismic');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources', 1));
    });

    it('passes filters back to frontend', function () {
        // Legacy ?type=doi uses exclude_type for backend filtering.
        // filters.type is empty so the frontend preserves ?type=doi in URLs.
        ($this->createPublishedPortalResource)('Test Dataset');

        // Clear only the portal facets cache so facets reflect the freshly
        // created resource without interfering with other cached values.
        // Use tag-aware forget to work on both array (no tags) and Redis (tags).
        $cacheKey = CacheKey::PORTAL_RESOURCE_TYPE_FACETS;
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags($cacheKey->tags())->forget($cacheKey->key());
        } else {
            Cache::forget($cacheKey->key());
        }

        $response = $this->get('/portal?q=test&type=doi');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->where('filters.query', 'test')
                    ->where('filters.type', [])
                    ->where('filters.exclude_type', 'physical-object')
            );
    });

    it('returns keyword suggestions', function () {
        $resource = ($this->createPublishedPortalResource)();
        Subject::factory()->create([
            'resource_id' => $resource->id,
            'value' => 'Seismology',
        ]);

        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->has('keywordSuggestions'));
    });

    it('returns pagination data', function () {
        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('pagination.current_page')
                    ->has('pagination.last_page')
                    ->has('pagination.total')
            );
    });

    it('returns map data', function () {
        $resource = ($this->createPublishedPortalResource)();
        GeoLocation::factory()->create([
            'resource_id' => $resource->id,
            'point_latitude' => 52.3792,
            'point_longitude' => 13.0658,
        ]);

        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->has('mapData'));
    });
});

describe('bounds parameter parsing', function () {
    it('passes valid bounds to filters', function () {
        $response = $this->get('/portal?north=53&south=51&east=14&west=12');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->where('filters.bounds', fn ($bounds) => $bounds['north'] == 53
                        && $bounds['south'] == 51
                        && $bounds['east'] == 14
                        && $bounds['west'] == 12
                    )
            );
    });

    it('passes null bounds when no bounds params are present', function () {
        $response = $this->get('/portal');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->where('filters.bounds', null)
            );
    });

    it('passes null bounds when only some params are present', function () {
        $response = $this->get('/portal?north=53&south=51');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.bounds', null));
    });

    it('passes null bounds for non-numeric values', function () {
        $response = $this->get('/portal?north=abc&south=51&east=14&west=12');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.bounds', null));
    });

    it('passes null bounds when latitude is out of range', function () {
        $response = $this->get('/portal?north=95&south=51&east=14&west=12');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.bounds', null));
    });

    it('passes null bounds when longitude is out of range', function () {
        $response = $this->get('/portal?north=53&south=51&east=200&west=12');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.bounds', null));
    });

    it('passes null bounds when north is less than south', function () {
        $response = $this->get('/portal?north=40&south=50&east=14&west=12');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.bounds', null));
    });

    it('accepts boundary values at range limits', function () {
        $response = $this->get('/portal?north=90&south=-90&east=180&west=-180');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('filters.bounds', fn ($bounds) => $bounds['north'] == 90
                        && $bounds['south'] == -90
                        && $bounds['east'] == 180
                        && $bounds['west'] == -180
                    )
            );
    });

    it('accepts north equal to south', function () {
        $response = $this->get('/portal?north=52&south=52&east=14&west=12');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('filters.bounds', fn ($bounds) => $bounds['north'] == 52
                        && $bounds['south'] == 52
                    )
            );
    });

    it('filters results when bounds are provided', function () {
        // Resource inside bounds (Berlin area)
        $inside = ($this->createPublishedPortalResource)('Berlin Dataset');
        GeoLocation::factory()->create([
            'resource_id' => $inside->id,
            'point_latitude' => 52.52,
            'point_longitude' => 13.405,
        ]);

        // Resource outside bounds (Rio)
        $outside = ($this->createPublishedPortalResource)('Rio Dataset');
        GeoLocation::factory()->create([
            'resource_id' => $outside->id,
            'point_latitude' => -22.9,
            'point_longitude' => -43.2,
        ]);

        $response = $this->get('/portal?north=54&south=50&east=15&west=11');

        $response->assertOk()
            ->assertInertia(fn ($page) => $page->has('resources', 1));
    });

    it('keeps all markers on map data even with bounds filter', function () {
        $inside = ($this->createPublishedPortalResource)('Berlin Dataset');
        GeoLocation::factory()->create([
            'resource_id' => $inside->id,
            'point_latitude' => 52.52,
            'point_longitude' => 13.405,
        ]);

        $outside = ($this->createPublishedPortalResource)('Rio Dataset');
        GeoLocation::factory()->create([
            'resource_id' => $outside->id,
            'point_latitude' => -22.9,
            'point_longitude' => -43.2,
        ]);

        $response = $this->get('/portal?north=54&south=50&east=15&west=11');

        // Results should be filtered (1 resource), but mapData should show both
        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->has('resources', 1)
                    ->has('mapData', 2)
            );
    });

    it('accepts decimal bounds values', function () {
        $response = $this->get('/portal?north=52.517400&south=51.349700&east=13.761200&west=12.237100');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->where('filters.bounds', fn ($bounds) => abs($bounds['north'] - 52.5174) < 0.0001
                        && abs($bounds['south'] - 51.3497) < 0.0001
                    )
            );
    });
});
