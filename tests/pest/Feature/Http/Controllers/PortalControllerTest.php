<?php

declare(strict_types=1);

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
        $response = $this->get('/portal?q=test&type=doi');

        $response->assertOk()
            ->assertInertia(
                fn ($page) => $page
                    ->component('portal')
                    ->where('filters.query', 'test')
                    ->where('filters.type', 'doi')
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
