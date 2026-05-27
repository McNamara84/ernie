<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\PortalSearchDailyStatistic;
use App\Models\Title;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class StatisticsDashboardService
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $endDate = CarbonImmutable::today();
        $startDate = $endDate->subDays(13);

        return [
            'overview' => $this->buildOverview(),
            'trends' => $this->buildTrends($startDate, $endDate),
            'topLandingPagesByViews' => $this->buildTopLandingPages('page_view_count', 'total_page_views'),
            'topLandingPagesByDownloads' => $this->buildTopLandingPages('file_download_click_count', 'total_download_clicks'),
            'portalSearchTerms' => $this->buildTopSearchTerms(),
            'typeSplit' => $this->buildTypeSplit(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildOverview(): array
    {
        /** @var object{total_page_views:int|string, total_download_clicks:int|string, tracked_landing_pages:int|string} $landingPageTotals */
        $landingPageTotals = DB::table('landing_page_daily_statistics')
            ->selectRaw('COALESCE(SUM(page_view_count), 0) as total_page_views')
            ->selectRaw('COALESCE(SUM(file_download_click_count), 0) as total_download_clicks')
            ->selectRaw('COUNT(DISTINCT landing_page_id) as tracked_landing_pages')
            ->first();

        return [
            'totalPageViews' => (int) $landingPageTotals->total_page_views,
            'totalDownloadClicks' => (int) $landingPageTotals->total_download_clicks,
            'totalPortalSearches' => (int) PortalSearchDailyStatistic::query()->sum('search_count'),
            'trackedLandingPages' => (int) $landingPageTotals->tracked_landing_pages,
        ];
    }

    /**
     * @return array{
     *     days: list<string>,
     *     pageViews: list<int>,
     *     downloadClicks: list<int>,
     *     portalSearches: list<int>
     * }
     */
    private function buildTrends(CarbonImmutable $startDate, CarbonImmutable $endDate): array
    {
        /** @var list<object{statistic_date:string, total_page_views:int|string, total_download_clicks:int|string}> $landingPageTrendRows */
        $landingPageTrendRows = DB::table('landing_page_daily_statistics')
            ->selectRaw('statistic_date, COALESCE(SUM(page_view_count), 0) as total_page_views')
            ->selectRaw('COALESCE(SUM(file_download_click_count), 0) as total_download_clicks')
            ->whereBetween('statistic_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('statistic_date')
            ->get()
            ->all();

        /** @var list<object{statistic_date:string, total_searches:int|string}> $searchTrendRows */
        $searchTrendRows = DB::table('portal_search_daily_statistics')
            ->selectRaw('statistic_date, COALESCE(SUM(search_count), 0) as total_searches')
            ->whereBetween('statistic_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->groupBy('statistic_date')
            ->get()
            ->all();

        /** @var array<string, array{pageViews:int, downloadClicks:int}> $landingPageRows */
        $landingPageRows = [];
        foreach ($landingPageTrendRows as $row) {
            $landingPageRows[$row->statistic_date] = [
                'pageViews' => (int) $row->total_page_views,
                'downloadClicks' => (int) $row->total_download_clicks,
            ];
        }

        /** @var array<string, int> $searchRows */
        $searchRows = [];
        foreach ($searchTrendRows as $row) {
            $searchRows[$row->statistic_date] = (int) $row->total_searches;
        }

        $days = [];
        $pageViews = [];
        $downloadClicks = [];
        $portalSearches = [];

        for ($date = $startDate; $date->lessThanOrEqualTo($endDate); $date = $date->addDay()) {
            $key = $date->toDateString();
            $days[] = $key;
            $pageViews[] = $landingPageRows[$key]['pageViews'] ?? 0;
            $downloadClicks[] = $landingPageRows[$key]['downloadClicks'] ?? 0;
            $portalSearches[] = $searchRows[$key] ?? 0;
        }

        return [
            'days' => $days,
            'pageViews' => $pageViews,
            'downloadClicks' => $downloadClicks,
            'portalSearches' => $portalSearches,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTopLandingPages(string $metricColumn, string $sumAlias): array
    {
        /** @var Collection<int, LandingPage> $landingPages */
        $landingPages = LandingPage::query()
            ->where('is_published', true)
            ->with([
                'resource.titles.titleType',
                'resource.resourceType',
            ])
            ->withSum('dailyStatistics as '.$sumAlias, $metricColumn)
            ->orderByDesc($sumAlias)
            ->limit(8)
            ->get()
            ->filter(fn (LandingPage $landingPage): bool => (int) ($landingPage->{$sumAlias} ?? 0) > 0)
            ->values();

        /** @var list<array<string, mixed>> $rows */
        $rows = array_values($landingPages
            ->map(function (LandingPage $landingPage) use ($sumAlias): array {
                $resourceType = $landingPage->resource->resourceType;

                return [
                    'landingPageId' => $landingPage->id,
                    'title' => $this->resolveLandingPageTitle($landingPage),
                    'identifier' => $landingPage->doi_prefix ?? ('draft-'.$landingPage->resource_id),
                    'resourceTypeLabel' => $resourceType instanceof \App\Models\ResourceType ? $resourceType->name : 'Other',
                    'total' => (int) ($landingPage->{$sumAlias} ?? 0),
                    'publicUrl' => $landingPage->public_url,
                    'isExternal' => $landingPage->isExternal(),
                ];
            })
            ->all());

        return $rows;
    }

    /**
     * @return list<array{term: string, total: int}>
     */
    private function buildTopSearchTerms(): array
    {
        /** @var list<object{normalized_term: string, total_searches: int|string}> $rows */
        $rows = PortalSearchDailyStatistic::query()
            ->selectRaw('normalized_term, SUM(search_count) as total_searches')
            ->groupBy('normalized_term')
            ->orderByDesc('total_searches')
            ->limit(10)
            ->get()
            ->all();

        return array_map(static fn (object $row): array => [
            'term' => $row->normalized_term,
            'total' => (int) $row->total_searches,
        ], $rows);
    }

    /**
     * @return array<string, int>
     */
    private function buildTypeSplit(): array
    {
        /** @var object{
         *     resource_page_views:int|string,
         *     physical_object_page_views:int|string,
         *     resource_download_clicks:int|string,
         *     physical_object_download_clicks:int|string
         * }|null $row
         */
        $row = DB::table('landing_page_daily_statistics')
            ->join('landing_pages', 'landing_pages.id', '=', 'landing_page_daily_statistics.landing_page_id')
            ->join('resources', 'resources.id', '=', 'landing_pages.resource_id')
            ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(resource_types.slug, '')) = 'physical-object' THEN page_view_count ELSE 0 END), 0) as physical_object_page_views")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(resource_types.slug, '')) = 'physical-object' THEN file_download_click_count ELSE 0 END), 0) as physical_object_download_clicks")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(resource_types.slug, '')) <> 'physical-object' OR resource_types.slug IS NULL THEN page_view_count ELSE 0 END), 0) as resource_page_views")
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(resource_types.slug, '')) <> 'physical-object' OR resource_types.slug IS NULL THEN file_download_click_count ELSE 0 END), 0) as resource_download_clicks")
            ->first();

        if ($row === null) {
            return [
                'resourcePageViews' => 0,
                'physicalObjectPageViews' => 0,
                'resourceDownloadClicks' => 0,
                'physicalObjectDownloadClicks' => 0,
            ];
        }

        return [
            'resourcePageViews' => (int) $row->resource_page_views,
            'physicalObjectPageViews' => (int) $row->physical_object_page_views,
            'resourceDownloadClicks' => (int) $row->resource_download_clicks,
            'physicalObjectDownloadClicks' => (int) $row->physical_object_download_clicks,
        ];
    }

    private function resolveLandingPageTitle(LandingPage $landingPage): string
    {
        $titles = $landingPage->resource->titles;

        if ($titles->isEmpty()) {
            return 'Untitled';
        }

        $mainTitle = $titles->first(fn (Title $title): bool => $title->isMainTitle());
        if ($mainTitle instanceof Title) {
            return $mainTitle->value;
        }

        /** @var Title $firstTitle */
        $firstTitle = $titles->first();

        return $firstTitle->value;
    }
}