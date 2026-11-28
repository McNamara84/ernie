<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Models\LandingPage;
use App\Models\Title;
use App\Services\Usage\CounterReadService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class StatisticsDashboardService
{
    /** @return array<string, mixed> */
    public function build(string $metric = 'unique', string $accessMethod = 'regular'): array
    {
        if (! in_array($metric, ['unique', 'total'], true) || ! in_array($accessMethod, ['regular', 'machine'], true)) {
            throw new InvalidArgumentException('Invalid COUNTER metric or access method.');
        }

        return app(CounterReadService::class)->snapshot(function () use ($metric, $accessMethod): array {
            $status = app(CounterReadService::class)->status();
            $views = $metric.'_investigations';
            $requests = $metric.'_requests';
            $downloads = "CASE WHEN COALESCE(resource_types.slug, '') <> 'physical-object' THEN {$requests} ELSE NULL END";
            $monthly = $this->metrics('usage_monthly_metrics', $accessMethod);
            $totals = (clone $monthly)->selectRaw("COALESCE(SUM({$views}), 0) AS views, COALESCE(SUM({$downloads}), 0) AS downloads, COUNT(DISTINCT landing_page_id) AS pages")->first();
            $searches = DB::table('usage_search_daily_metrics')->where('access_method', ucfirst($accessMethod));
            $searchTerms = (clone $searches)->selectRaw('term_hash, MIN(normalized_term) as term, SUM(search_count) as total')
                ->groupBy('term_hash')->orderByDesc('total')->orderBy('term_hash')->limit(10)->get()
                ->map(fn (object $row): array => ['term' => $row->term, 'total' => (int) $row->total])->all();
            $types = (clone $monthly)->selectRaw("COALESCE(resource_types.slug, '') as slug, SUM({$views}) as views, SUM({$downloads}) as downloads")
                ->groupBy('resource_types.slug')->get();
            $split = ['resourcePageViews' => 0, 'physicalObjectPageViews' => 0, 'resourceDownloadClicks' => 0];
            foreach ($types as $type) {
                if ($type->slug === 'physical-object') {
                    $split['physicalObjectPageViews'] += (int) $type->views;
                } else {
                    $split['resourcePageViews'] += (int) $type->views;
                    $split['resourceDownloadClicks'] += (int) $type->downloads;
                }
            }
            $trends = ['days' => [], 'pageViews' => [], 'downloadClicks' => [], 'portalSearches' => []];
            if ($status['dataThrough'] !== null) {
                $end = CarbonImmutable::parse($status['dataThrough'], 'UTC');
                $start = $end->subDays(13)->max(CarbonImmutable::parse($status['startedAt'], 'UTC')->startOfDay());
                $daily = $this->metrics('usage_daily_metrics', $accessMethod)->whereBetween('day', [$start->toDateString(), $end->toDateString()])
                    ->selectRaw("day, SUM({$views}) as views, SUM({$downloads}) as downloads")->groupBy('day')->get()->keyBy('day');
                $searchDaily = (clone $searches)->whereBetween('day', [$start->toDateString(), $end->toDateString()])
                    ->selectRaw('day, SUM(search_count) as total')->groupBy('day')->get()->keyBy('day');
                for ($date = $start; $date->lte($end); $date = $date->addDay()) {
                    $day = $date->toDateString();
                    $trends['days'][] = $day;
                    $trends['pageViews'][] = (int) ($daily->get($day)->views ?? 0);
                    $trends['downloadClicks'][] = (int) ($daily->get($day)->downloads ?? 0);
                    $trends['portalSearches'][] = (int) ($searchDaily->get($day)->total ?? 0);
                }
            }

            return [
                'usageStatus' => $status,
                'overview' => [
                    'totalPageViews' => (int) ($totals->views ?? 0), 'totalDownloadClicks' => (int) ($totals->downloads ?? 0),
                    'trackedLandingPages' => (int) ($totals->pages ?? 0), 'totalPortalSearches' => (int) (clone $searches)->sum('search_count'),
                ],
                'trends' => $trends, 'typeSplit' => $split, 'portalSearchTerms' => $searchTerms,
                'topLandingPagesByViews' => $this->top($monthly, $views),
                'topLandingPagesByDownloads' => $this->top($monthly, $requests, true),
            ];
        });
    }

    private function metrics(string $table, string $accessMethod): Builder
    {
        return DB::table($table)->where('access_method', ucfirst($accessMethod))
            ->join('landing_pages', 'landing_pages.id', '=', $table.'.landing_page_id')
            ->join('resources', 'resources.id', '=', 'landing_pages.resource_id')
            ->leftJoin('resource_types', 'resource_types.id', '=', 'resources.resource_type_id');
    }

    /**
     * @param  literal-string  $metric
     * @return list<array<string, mixed>>
     */
    private function top(Builder $query, string $metric, bool $downloads = false): array
    {
        $rows = (clone $query)->when($downloads, fn (Builder $query) => $query->whereRaw("COALESCE(resource_types.slug, '') <> 'physical-object'"))
            ->selectRaw("landing_page_id, SUM({$metric}) as total")->groupBy('landing_page_id')
            ->havingRaw("SUM({$metric}) > 0")->orderByDesc('total')->orderBy('landing_page_id')->limit(8)->get();
        $pages = LandingPage::query()->with(['resource.titles.titleType', 'resource.resourceType'])
            ->whereIn('id', $rows->pluck('landing_page_id'))->get()->keyBy('id');

        return array_values($rows->map(function (object $row) use ($pages): array {
            $page = $pages->get($row->landing_page_id);
            $title = $page?->resource->titles->first(fn (Title $title): bool => $title->isMainTitle()) ?? $page?->resource->titles->first();

            return [
                'landingPageId' => (int) $row->landing_page_id, 'title' => $title->value ?? 'Untitled',
                'identifier' => $page->doi_prefix ?? '', 'resourceTypeLabel' => $page?->resource->resourceType->name ?? 'Other',
                'total' => (int) $row->total, 'publicUrl' => $page->public_url ?? '', 'isExternal' => false,
            ];
        })->all());
    }
}
