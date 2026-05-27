<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\PortalSearchDailyStatistic;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\BotProtection\BotClassifierService;
use App\Services\Statistics\LandingPageAnalyticsService;
use App\Services\Statistics\PortalSearchAnalyticsService;
use App\Services\Statistics\StatisticsDashboardService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

covers(
    StatisticsDashboardService::class,
    PortalSearchAnalyticsService::class,
    LandingPageAnalyticsService::class,
    LandingPageDailyStatistic::class,
    LandingPage::class,
);

beforeEach(function (): void {
    Carbon::setTestNow('2026-05-27 12:00:00');

    config([
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot'],
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function statisticsTitleType(string $slug, string $name): TitleType
{
    return TitleType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'slug' => $slug, 'is_active' => true, 'is_elmo_active' => true],
    );
}

function statisticsResourceType(string $slug, string $name): ResourceType
{
    return ResourceType::firstOrCreate(
        ['slug' => $slug],
        ['name' => $name, 'slug' => $slug, 'is_active' => true, 'is_elmo_active' => true],
    );
}

function statisticsRequest(string $userAgent = 'Mozilla/5.0', string $ipAddress = '203.0.113.50'): Request
{
    return Request::create('/portal/search-analytics', 'POST', server: [
        'REMOTE_ADDR' => $ipAddress,
        'HTTP_USER_AGENT' => $userAgent,
    ]);
}

function statisticsLandingPage(
    string $doi,
    string $slug,
    ?ResourceType $resourceType = null,
    ?string $title = null,
    ?TitleType $titleType = null,
): LandingPage {
    $resource = Resource::factory()->create([
        'doi' => $doi,
        'resource_type_id' => $resourceType?->id,
    ]);

    if ($title !== null) {
        Title::factory()->create([
            'resource_id' => $resource->id,
            'title_type_id' => $titleType?->id,
            'value' => $title,
        ]);
    }

    return LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'doi_prefix' => $doi,
        'slug' => $slug,
    ]);
}

describe('LandingPageAnalyticsService', function () {
    it('records page views and file download clicks in the same daily aggregate row', function (): void {
        $service = new LandingPageAnalyticsService;
        $landingPage = statisticsLandingPage('10.5880/stats.analytics.001', 'stats-analytics');

        $service->recordPageView($landingPage);
        $service->recordPageView($landingPage);
        $service->recordFileDownloadClick($landingPage);

        $statistic = LandingPageDailyStatistic::query()->sole();

        expect($statistic->landing_page_id)->toBe($landingPage->id)
            ->and($statistic->page_view_count)->toBe(2)
            ->and($statistic->file_download_click_count)->toBe(1)
            ->and($statistic->statistic_date->toDateString())->toBe('2026-05-27');
    });

    it('rejects unsupported aggregate columns defensively', function (): void {
        $service = new LandingPageAnalyticsService;
        $method = new ReflectionMethod($service, 'incrementExpression');
        $method->setAccessible(true);

        expect(fn (): mixed => $method->invoke($service, 'unexpected_counter'))
            ->toThrow(InvalidArgumentException::class, 'Unsupported analytics counter column.');
    });
});

describe('PortalSearchAnalyticsService', function () {
    it('normalizes whitespace and case before aggregating searches', function (): void {
        $service = new PortalSearchAnalyticsService(new BotClassifierService);
        $request = statisticsRequest();

        $service->recordSearch($request, '  GFZ   DATA  ');
        $service->recordSearch($request, 'gfz data');

        $statistic = PortalSearchDailyStatistic::query()->sole();

        expect($statistic->normalized_term)->toBe('gfz data')
            ->and($statistic->search_count)->toBe(2)
            ->and($service->normalizeTerm("\nGFZ\tDATA\n"))->toBe('gfz data');
    });

    it('ignores blank search submissions before touching the aggregate table', function (): void {
        $service = new PortalSearchAnalyticsService(new BotClassifierService);

        $service->recordSearch(statisticsRequest(), '   ');

        expect(PortalSearchDailyStatistic::query()->count())->toBe(0);
    });

    it('skips bots when protection is enabled and records them when protection is disabled', function (): void {
        $service = new PortalSearchAnalyticsService(new BotClassifierService);
        $botRequest = statisticsRequest(userAgent: 'GPTBot');

        $service->recordSearch($botRequest, 'Core Samples');

        expect(PortalSearchDailyStatistic::query()->count())->toBe(0)
            ->and($service->normalizeTerm(null))->toBe('')
            ->and($service->normalizeTerm('   '))->toBe('');

        config(['bot_protection.enabled' => false]);

        $service->recordSearch($botRequest, 'Core Samples');

        expect(PortalSearchDailyStatistic::query()
            ->where('normalized_term', 'core samples')
            ->value('search_count'))->toBe(1);
    });
});

describe('StatisticsDashboardService', function () {
    it('builds the statistics dashboard from landing page and portal aggregates', function (): void {
        $mainTitleType = statisticsTitleType('MainTitle', 'Main Title');
        $alternativeTitleType = statisticsTitleType('AlternativeTitle', 'Alternative Title');
        $datasetType = statisticsResourceType('dataset', 'Dataset');
        $physicalObjectType = statisticsResourceType('physical-object', 'Physical Object');

        $datasetLandingPage = statisticsLandingPage(
            '10.5880/stats.dataset.001',
            'dataset-alpha',
            $datasetType,
            'Dataset Alpha',
            $mainTitleType,
        );

        $sampleLandingPage = statisticsLandingPage(
            '10.5880/stats.sample.001',
            'sample-beta',
            $physicalObjectType,
            'Sample Beta',
            $mainTitleType,
        );

        $alternativeTitleLandingPage = statisticsLandingPage(
            '10.5880/stats.alt.001',
            'alternative-title',
            null,
            'Alternative Title',
            $alternativeTitleType,
        );

        $untitledLandingPage = statisticsLandingPage(
            '10.5880/stats.untitled.001',
            'untitled-resource',
            $datasetType,
        );

        statisticsLandingPage(
            '10.5880/stats.zero.001',
            'zero-stats-resource',
            $datasetType,
            'Zero Stats',
            $mainTitleType,
        );

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $datasetLandingPage->id,
            'statistic_date' => now()->toDateString(),
            'page_view_count' => 10,
            'file_download_click_count' => 2,
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $datasetLandingPage->id,
            'statistic_date' => now()->subDays(20)->toDateString(),
            'page_view_count' => 5,
            'file_download_click_count' => 1,
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $sampleLandingPage->id,
            'statistic_date' => now()->subDay()->toDateString(),
            'page_view_count' => 4,
            'file_download_click_count' => 7,
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $alternativeTitleLandingPage->id,
            'statistic_date' => now()->toDateString(),
            'page_view_count' => 3,
            'file_download_click_count' => 1,
        ]);

        LandingPageDailyStatistic::query()->create([
            'landing_page_id' => $untitledLandingPage->id,
            'statistic_date' => now()->subDays(2)->toDateString(),
            'page_view_count' => 2,
            'file_download_click_count' => 1,
        ]);

        PortalSearchDailyStatistic::query()->create([
            'statistic_date' => now()->toDateString(),
            'normalized_term' => 'climate',
            'search_count' => 5,
        ]);

        PortalSearchDailyStatistic::query()->create([
            'statistic_date' => now()->subDay()->toDateString(),
            'normalized_term' => 'igsn',
            'search_count' => 2,
        ]);

        PortalSearchDailyStatistic::query()->create([
            'statistic_date' => now()->subDays(20)->toDateString(),
            'normalized_term' => 'climate',
            'search_count' => 4,
        ]);

        $dashboard = (new StatisticsDashboardService)->build();

        expect($dashboard['overview'])->toBe([
            'totalPageViews' => 24,
            'totalDownloadClicks' => 12,
            'totalPortalSearches' => 11,
            'trackedLandingPages' => 4,
        ]);

        expect($dashboard['trends']['days'])->toHaveCount(14)
            ->and($dashboard['trends']['days'][13])->toBe('2026-05-27')
            ->and($dashboard['trends']['pageViews'][13])->toBe(13)
            ->and($dashboard['trends']['downloadClicks'][13])->toBe(3)
            ->and($dashboard['trends']['pageViews'][12])->toBe(4)
            ->and($dashboard['trends']['pageViews'][11])->toBe(2)
            ->and($dashboard['trends']['portalSearches'][13])->toBe(5)
            ->and($dashboard['trends']['portalSearches'][12])->toBe(2);

        expect(array_column($dashboard['topLandingPagesByViews'], 'title'))->toBe([
            'Dataset Alpha',
            'Sample Beta',
            'Alternative Title',
            'Untitled',
        ]);

        expect($dashboard['topLandingPagesByViews'][0]['total'])->toBe(15)
            ->and($dashboard['topLandingPagesByViews'][2]['resourceTypeLabel'])->toBe('Other')
            ->and(count($dashboard['topLandingPagesByViews']))->toBe(4)
            ->and($dashboard['topLandingPagesByDownloads'][0]['title'])->toBe('Sample Beta')
            ->and($dashboard['topLandingPagesByDownloads'][0]['total'])->toBe(7)
            ->and($dashboard['portalSearchTerms'][0])->toBe([
                'term' => 'climate',
                'total' => 9,
            ])
            ->and($dashboard['typeSplit'])->toBe([
                'resourcePageViews' => 20,
                'physicalObjectPageViews' => 4,
                'resourceDownloadClicks' => 5,
                'physicalObjectDownloadClicks' => 7,
            ]);
    });

    it('returns empty dashboard state when no statistics exist', function (): void {
        $dashboard = (new StatisticsDashboardService)->build();

        expect($dashboard['overview'])->toBe([
            'totalPageViews' => 0,
            'totalDownloadClicks' => 0,
            'totalPortalSearches' => 0,
            'trackedLandingPages' => 0,
        ])
            ->and($dashboard['topLandingPagesByViews'])->toBe([])
            ->and($dashboard['topLandingPagesByDownloads'])->toBe([])
            ->and($dashboard['portalSearchTerms'])->toBe([])
            ->and($dashboard['typeSplit'])->toBe([
                'resourcePageViews' => 0,
                'physicalObjectPageViews' => 0,
                'resourceDownloadClicks' => 0,
                'physicalObjectDownloadClicks' => 0,
            ])
            ->and($dashboard['trends']['days'])->toHaveCount(14)
            ->and(array_sum($dashboard['trends']['pageViews']))->toBe(0)
            ->and(array_sum($dashboard['trends']['downloadClicks']))->toBe(0)
            ->and(array_sum($dashboard['trends']['portalSearches']))->toBe(0);
    });
});

it('casts landing page daily statistics and exposes analytics relationships', function (): void {
    $landingPage = statisticsLandingPage('10.5880/stats.model.001', 'stats-model');
    $statistic = new LandingPageDailyStatistic([
        'landing_page_id' => $landingPage->id,
        'statistic_date' => '2026-05-27',
        'page_view_count' => '2',
        'file_download_click_count' => '3',
    ]);

    expect($statistic->getFillable())->toBe([
        'landing_page_id',
        'statistic_date',
        'page_view_count',
        'file_download_click_count',
    ])
        ->and($statistic->statistic_date)->toBeInstanceOf(Carbon::class)
        ->and($statistic->page_view_count)->toBeInt()
        ->and($statistic->file_download_click_count)->toBeInt()
        ->and($statistic->landingPage())->toBeInstanceOf(BelongsTo::class)
        ->and($landingPage->dailyStatistics())->toBeInstanceOf(HasMany::class);
});