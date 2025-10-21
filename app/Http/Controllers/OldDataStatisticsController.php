<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OldDataStatisticsController extends Controller
{
    private const DATASET_CONNECTION = 'metaworks';
    private const CACHE_KEY_PREFIX = 'old_data_stats_';
    private const CACHE_DURATION = 60 * 60 * 12; // 12 Stunden in Sekunden

    /**
     * Display statistics dashboard for old datasets.
     *
     * @return \Inertia\Response
     */
    public function index(Request $request): Response
    {
        // Check if cache should be refreshed
        $forceRefresh = $request->boolean('refresh', false);

        if ($forceRefresh) {
            $this->clearCache();
        }

        $statistics = [
            'overview' => $this->getOverviewStats(),
            'institutions' => $this->getInstitutionStats(),
            'relatedWorks' => $this->getRelatedWorksStats(),
            'pidUsage' => $this->getPidUsageStats(),
            'completeness' => $this->getCompletenessStats(),
            'curators' => $this->getCuratorStats(),
            'roles' => $this->getRoleStats(),
            'timeline' => $this->getTimelineStats(),
            'resourceTypes' => $this->getResourceTypeStats(),
            'languages' => $this->getLanguageStats(),
            'licenses' => $this->getLicenseStats(),
        ];

        return Inertia::render('old-statistics', [
            'statistics' => $statistics,
            'lastUpdated' => now()->toIso8601String(),
        ]);
    }

    /**
     * Clear all cached statistics.
     */
    private function clearCache(): void
    {
        $cacheKeys = [
            'overview',
            'institutions',
            'related_works',
            'pid_usage',
            'completeness',
            'curators',
            'roles',
            'timeline',
            'resource_types',
            'languages',
            'licenses',
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget(self::CACHE_KEY_PREFIX . $key);
        }
    }

    /**
     * Get overview statistics (total counts, averages, etc.).
     *
     * @return array<string, mixed>
     */
    private function getOverviewStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'overview',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Total datasets
                $totalDatasets = $db->table('resource')->count();

                // Total authors (unique persons with Creator role)
                $totalAuthors = $db->table('resourceagent')
                    ->join('role', function ($join) {
                        $join->on('resourceagent.resource_id', '=', 'role.resourceagent_resource_id')
                            ->on('resourceagent.order', '=', 'role.resourceagent_order');
                    })
                    ->where('role.role', 'Creator')
                    ->distinct()
                    ->count('resourceagent.id');

                // Average authors per dataset
                $avgAuthorsPerDataset = $db->table('resourceagent')
                    ->join('role', function ($join) {
                        $join->on('resourceagent.resource_id', '=', 'role.resourceagent_resource_id')
                            ->on('resourceagent.order', '=', 'role.resourceagent_order');
                    })
                    ->where('role.role', 'Creator')
                    ->select(DB::raw('COUNT(*) / COUNT(DISTINCT resourceagent.resource_id) as avg'))
                    ->value('avg');

                // Average contributors per dataset (non-Creator roles)
                $avgContributorsPerDataset = $db->table('resourceagent')
                    ->join('role', function ($join) {
                        $join->on('resourceagent.resource_id', '=', 'role.resourceagent_resource_id')
                            ->on('resourceagent.order', '=', 'role.resourceagent_order');
                    })
                    ->where('role.role', '!=', 'Creator')
                    ->select(DB::raw('COUNT(*) / COUNT(DISTINCT resourceagent.resource_id) as avg'))
                    ->value('avg');

                // Average related works per dataset
                $avgRelatedWorks = $db->table('relatedidentifier')
                    ->select(DB::raw('COUNT(*) / COUNT(DISTINCT resource_id) as avg'))
                    ->value('avg');

                // Oldest dataset (by publication year)
                /** @var object{id: int, identifier: string, publicationyear: int}|null $oldestDataset */
                $oldestDataset = $db->table('resource')
                    ->whereNotNull('publicationyear')
                    ->where('publicationyear', '>', 0)
                    ->orderBy('publicationyear', 'asc')
                    ->select('id', 'identifier', 'publicationyear')
                    ->first();

                // Newest dataset (by publication year)
                /** @var object{id: int, identifier: string, publicationyear: int}|null $newestDataset */
                $newestDataset = $db->table('resource')
                    ->whereNotNull('publicationyear')
                    ->where('publicationyear', '>', 0)
                    ->orderBy('publicationyear', 'desc')
                    ->select('id', 'identifier', 'publicationyear')
                    ->first();

                // Oldest dataset (by created_at)
                /** @var object{id: int, identifier: string, created_at: string}|null $oldestCreated */
                $oldestCreated = $db->table('resource')
                    ->whereNotNull('created_at')
                    ->orderBy('created_at', 'asc')
                    ->select('id', 'identifier', 'created_at')
                    ->first();

                // Newest dataset (by created_at)
                /** @var object{id: int, identifier: string, created_at: string}|null $newestCreated */
                $newestCreated = $db->table('resource')
                    ->whereNotNull('created_at')
                    ->orderBy('created_at', 'desc')
                    ->select('id', 'identifier', 'created_at')
                    ->first();

                return [
                    'totalDatasets' => $totalDatasets,
                    'totalAuthors' => $totalAuthors,
                    'avgAuthorsPerDataset' => round((float) $avgAuthorsPerDataset, 2),
                    'avgContributorsPerDataset' => round((float) $avgContributorsPerDataset, 2),
                    'avgRelatedWorks' => round((float) $avgRelatedWorks, 2),
                    'oldestDataset' => $oldestDataset ? [
                        'id' => $oldestDataset->id,
                        'identifier' => $oldestDataset->identifier,
                        'year' => $oldestDataset->publicationyear,
                    ] : null,
                    'newestDataset' => $newestDataset ? [
                        'id' => $newestDataset->id,
                        'identifier' => $newestDataset->identifier,
                        'year' => $newestDataset->publicationyear,
                    ] : null,
                    'oldestCreated' => $oldestCreated ? [
                        'id' => $oldestCreated->id,
                        'identifier' => $oldestCreated->identifier,
                        'createdAt' => $oldestCreated->created_at,
                    ] : null,
                    'newestCreated' => $newestCreated ? [
                        'id' => $newestCreated->id,
                        'identifier' => $newestCreated->identifier,
                        'createdAt' => $newestCreated->created_at,
                    ] : null,
                ];
            }
        );
    }

    /**
     * Get institution statistics (top publishing institutions).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getInstitutionStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'institutions',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('affiliation')
                    ->select([
                        'affiliation.name as institution_name',
                        'affiliation.identifier as ror_id',
                        DB::raw('COUNT(DISTINCT affiliation.resourceagent_resource_id) as dataset_count'),
                    ])
                    ->whereNotNull('affiliation.name')
                    ->where('affiliation.name', '!=', '')
                    ->groupBy('affiliation.name', 'affiliation.identifier')
                    ->orderBy('dataset_count', 'desc')
                    ->limit(15)
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'name' => $row->institution_name,
                        'rorId' => $row->ror_id,
                        'count' => (int) $row->dataset_count,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get related works statistics (distribution and top datasets).
     *
     * @return array<string, mixed>
     */
    private function getRelatedWorksStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'related_works',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Top 20 datasets with most related works
                $topDatasets = $db->table('resource')
                    ->leftJoin('title', 'resource.id', '=', 'title.resource_id')
                    ->leftJoin('relatedidentifier', 'resource.id', '=', 'relatedidentifier.resource_id')
                    ->select([
                        'resource.id',
                        'resource.identifier',
                        'title.title',
                        DB::raw('COUNT(relatedidentifier.id) as related_count'),
                    ])
                    ->groupBy('resource.id', 'resource.identifier', 'title.title')
                    ->having('related_count', '>', 0)
                    ->orderBy('related_count', 'desc')
                    ->limit(20)
                    ->get();

                // Distribution (histogram data)
                $distribution = $db->table(DB::raw('(
                    SELECT 
                        resource_id,
                        COUNT(*) as count
                    FROM relatedidentifier
                    GROUP BY resource_id
                ) as counts'))
                    ->select([
                        DB::raw('CASE 
                            WHEN count BETWEEN 1 AND 10 THEN "1-10"
                            WHEN count BETWEEN 11 AND 25 THEN "11-25"
                            WHEN count BETWEEN 26 AND 50 THEN "26-50"
                            WHEN count BETWEEN 51 AND 100 THEN "51-100"
                            WHEN count BETWEEN 101 AND 200 THEN "101-200"
                            WHEN count BETWEEN 201 AND 400 THEN "201-400"
                            WHEN count > 400 THEN "400+"
                        END as range'),
                        DB::raw('COUNT(*) as datasets'),
                    ])
                    ->groupBy('range')
                    ->get();

                return [
                    'topDatasets' => $topDatasets->map(function ($row) {
                        return [
                            'id' => $row->id,
                            'identifier' => $row->identifier,
                            'title' => $row->title,
                            'count' => (int) $row->related_count,
                        ];
                    })->toArray(),
                    'distribution' => $distribution->map(function ($row) {
                        return [
                            'range' => $row->range,
                            'count' => (int) $row->datasets,
                        ];
                    })->toArray(),
                ];
            }
        );
    }

    /**
     * Get PID usage statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPidUsageStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'pid_usage',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('relatedidentifier')
                    ->select([
                        'identifiertype',
                        DB::raw('COUNT(*) as count'),
                        DB::raw('ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM relatedidentifier), 2) as percentage'),
                    ])
                    ->whereNotNull('identifiertype')
                    ->groupBy('identifiertype')
                    ->orderBy('count', 'desc')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'type' => $row->identifiertype,
                        'count' => (int) $row->count,
                        'percentage' => (float) $row->percentage,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get data completeness statistics.
     *
     * @return array<string, float>
     */
    private function getCompletenessStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'completeness',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $totalDatasets = $db->table('resource')->count();

                if ($totalDatasets === 0) {
                    return [];
                }

                // Datasets with descriptions
                $withDescriptions = $db->table('resource')
                    ->join('description', 'resource.id', '=', 'description.resource_id')
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with geographic coverage
                $withCoverage = $db->table('resource')
                    ->join('coverage', 'resource.id', '=', 'coverage.resource_id')
                    ->whereNotNull('coverage.minlat')
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with temporal coverage
                $withTemporalCoverage = $db->table('resource')
                    ->join('coverage', 'resource.id', '=', 'coverage.resource_id')
                    ->whereNotNull('coverage.start')
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with funding
                $withFunding = $db->table('resource')
                    ->join('funding', 'resource.id', '=', 'funding.resource_id')
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with at least one author having ORCID
                $withOrcid = $db->table('resource')
                    ->join('resourceagent', 'resource.id', '=', 'resourceagent.resource_id')
                    ->whereNotNull('resourceagent.identifier')
                    ->whereRaw('UPPER(resourceagent.identifiertype) = ?', ['ORCID'])
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with at least one affiliation having ROR ID
                $withRor = $db->table('resource')
                    ->join('affiliation', 'resource.id', '=', 'affiliation.resourceagent_resource_id')
                    ->whereNotNull('affiliation.identifier')
                    ->where(function ($query) {
                        $query->where('affiliation.identifiertype', 'ROR')
                            ->orWhere('affiliation.identifier', 'like', 'https://ror.org/%');
                    })
                    ->distinct('resource.id')
                    ->count('resource.id');

                // Datasets with related identifiers
                $withRelatedWorks = $db->table('resource')
                    ->join('relatedidentifier', 'resource.id', '=', 'relatedidentifier.resource_id')
                    ->distinct('resource.id')
                    ->count('resource.id');

                return [
                    'descriptions' => round(($withDescriptions / $totalDatasets) * 100, 2),
                    'geographicCoverage' => round(($withCoverage / $totalDatasets) * 100, 2),
                    'temporalCoverage' => round(($withTemporalCoverage / $totalDatasets) * 100, 2),
                    'funding' => round(($withFunding / $totalDatasets) * 100, 2),
                    'orcid' => round(($withOrcid / $totalDatasets) * 100, 2),
                    'rorIds' => round(($withRor / $totalDatasets) * 100, 2),
                    'relatedWorks' => round(($withRelatedWorks / $totalDatasets) * 100, 2),
                ];
            }
        );
    }

    /**
     * Get curator statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCuratorStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'curators',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('resource')
                    ->select([
                        'curator',
                        DB::raw('COUNT(*) as datasets_curated'),
                    ])
                    ->whereNotNull('curator')
                    ->where('curator', '!=', '')
                    ->groupBy('curator')
                    ->orderBy('datasets_curated', 'desc')
                    ->limit(15)
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'name' => $row->curator,
                        'count' => (int) $row->datasets_curated,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get contributor role statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getRoleStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'roles',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('role')
                    ->select([
                        'role',
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('role')
                    ->groupBy('role')
                    ->orderBy('count', 'desc')
                    ->limit(15)
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'role' => $row->role,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get timeline statistics (publications over time).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTimelineStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'timeline',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Publications by year
                $publicationsByYear = $db->table('resource')
                    ->select([
                        'publicationyear as year',
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('publicationyear')
                    ->where('publicationyear', '>', 1900)
                    ->where('publicationyear', '<=', date('Y'))
                    ->groupBy('publicationyear')
                    ->orderBy('publicationyear', 'asc')
                    ->get();

                // Datasets created by year
                $createdByYear = $db->table('resource')
                    ->select([
                        DB::raw('YEAR(created_at) as year'),
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('created_at')
                    ->groupBy(DB::raw('YEAR(created_at)'))
                    ->orderBy('year', 'asc')
                    ->get();

                return [
                    'publicationsByYear' => $publicationsByYear->map(function ($row) {
                        return [
                            'year' => (int) $row->year,
                            'count' => (int) $row->count,
                        ];
                    })->toArray(),
                    'createdByYear' => $createdByYear->map(function ($row) {
                        return [
                            'year' => (int) $row->year,
                            'count' => (int) $row->count,
                        ];
                    })->toArray(),
                ];
            }
        );
    }

    /**
     * Get resource type distribution statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getResourceTypeStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'resource_types',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('resource')
                    ->select([
                        'resourcetypegeneral',
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('resourcetypegeneral')
                    ->groupBy('resourcetypegeneral')
                    ->orderBy('count', 'desc')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'type' => $row->resourcetypegeneral,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get language distribution statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getLanguageStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'languages',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('resource')
                    ->select([
                        'language',
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('language')
                    ->where('language', '!=', '')
                    ->groupBy('language')
                    ->orderBy('count', 'desc')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'language' => $row->language,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get license distribution statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getLicenseStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'licenses',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('license')
                    ->select([
                        'name',
                        DB::raw('COUNT(DISTINCT resource_id) as count'),
                    ])
                    ->whereNotNull('name')
                    ->groupBy('name')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'name' => $row->name,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }
}
