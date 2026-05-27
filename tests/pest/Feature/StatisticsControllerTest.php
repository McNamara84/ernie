<?php

declare(strict_types=1);

use App\Http\Controllers\StatisticsController;
use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\PortalSearchDailyStatistic;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;

covers(StatisticsController::class);

uses()->group('statistics');

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->groupLeader = User::factory()->groupLeader()->create();
    $this->curator = User::factory()->curator()->create();
});

describe('statistics access', function () {
    test('requires authentication', function () {
        $this->get('/statistics')->assertRedirect(route('login'));
    });

    test('allows admins and group leaders', function () {
        $this->actingAs($this->admin)->get('/statistics')->assertOk();
        $this->actingAs($this->groupLeader)->get('/statistics')->assertOk();
    });

    test('denies curators', function () {
        $this->actingAs($this->curator)->get('/statistics')->assertForbidden();
    });
});

describe('statistics payload', function () {
    test('returns aggregated analytics data', function () {
        $mainTitleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true],
        );

        $datasetType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset', 'slug' => 'dataset', 'is_active' => true],
        );

        $physicalObjectType = ResourceType::firstOrCreate(
            ['slug' => 'physical-object'],
            ['name' => 'Physical Object', 'slug' => 'physical-object', 'is_active' => true],
        );

        $datasetResource = Resource::factory()->create([
            'doi' => '10.5880/stats.dataset.001',
            'resource_type_id' => $datasetType->id,
        ]);

        Title::factory()->create([
            'resource_id' => $datasetResource->id,
            'title_type_id' => $mainTitleType->id,
            'value' => 'Dataset Title',
        ]);

        $sampleResource = Resource::factory()->create([
            'doi' => '10.5880/stats.sample.001',
            'resource_type_id' => $physicalObjectType->id,
        ]);

        Title::factory()->create([
            'resource_id' => $sampleResource->id,
            'title_type_id' => $mainTitleType->id,
            'value' => 'Sample Title',
        ]);

        $datasetLandingPage = LandingPage::factory()->published()->create([
            'resource_id' => $datasetResource->id,
            'doi_prefix' => '10.5880/stats.dataset.001',
            'slug' => 'dataset-title',
        ]);

        $sampleLandingPage = LandingPage::factory()->published()->create([
            'resource_id' => $sampleResource->id,
            'doi_prefix' => '10.5880/stats.sample.001',
            'slug' => 'sample-title',
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $datasetLandingPage->id,
            'statistic_date' => now()->subDay()->toDateString(),
            'page_view_count' => 8,
            'file_download_click_count' => 3,
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $sampleLandingPage->id,
            'statistic_date' => now()->toDateString(),
            'page_view_count' => 2,
            'file_download_click_count' => 1,
        ]);

        PortalSearchDailyStatistic::query()->create([
            'statistic_date' => now()->toDateString(),
            'normalized_term' => 'climate',
            'search_count' => 5,
        ]);

        PortalSearchDailyStatistic::query()->create([
            'statistic_date' => now()->toDateString(),
            'normalized_term' => 'igsn',
            'search_count' => 2,
        ]);

        $this->actingAs($this->admin)
            ->get('/statistics')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statistics')
                ->where('overview.totalPageViews', 10)
                ->where('overview.totalDownloadClicks', 4)
                ->where('overview.totalPortalSearches', 7)
                ->where('overview.trackedLandingPages', 2)
                ->where('topLandingPagesByViews.0.title', 'Dataset Title')
                ->where('topLandingPagesByDownloads.0.title', 'Dataset Title')
                ->where('portalSearchTerms.0.term', 'climate')
                ->where('typeSplit.resourcePageViews', 8)
                ->where('typeSplit.physicalObjectPageViews', 2)
                ->has('trends.days', 14)
            );
    });
});