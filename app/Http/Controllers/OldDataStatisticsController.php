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
            'identifiers' => $this->getIdentifierStats(),
            'current_year' => $this->getCurrentYearStats(),
            'affiliations' => $this->getAffiliationStats(),
            'keywords' => $this->getKeywordStats(),
            'creation_time' => $this->getCreationTimeStats(),
            'descriptions' => $this->getDescriptionStats(),
            'publication_years' => $this->getPublicationYearStats(),
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
                // Count distinct resource_id + order combinations (composite primary key)
                $totalAuthors = $db->table('resourceagent')
                    ->join('role', function ($join) {
                        $join->on('resourceagent.resource_id', '=', 'role.resourceagent_resource_id')
                            ->on('resourceagent.order', '=', 'role.resourceagent_order');
                    })
                    ->where('role.role', 'Creator')
                    ->count(DB::raw('DISTINCT CONCAT(resourceagent.resource_id, "-", resourceagent.order)'));

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
                // MySQL 5.x compatible version - use backticks for reserved keyword and simpler syntax
                $distribution = $db->select('
                    SELECT 
                        CASE 
                            WHEN cnt BETWEEN 1 AND 10 THEN "1-10"
                            WHEN cnt BETWEEN 11 AND 25 THEN "11-25"
                            WHEN cnt BETWEEN 26 AND 50 THEN "26-50"
                            WHEN cnt BETWEEN 51 AND 100 THEN "51-100"
                            WHEN cnt BETWEEN 101 AND 200 THEN "101-200"
                            WHEN cnt BETWEEN 201 AND 400 THEN "201-400"
                            WHEN cnt > 400 THEN "400+"
                        END as `range_label`,
                        COUNT(*) as datasets
                    FROM (
                        SELECT resource_id, COUNT(*) as cnt
                        FROM relatedidentifier
                        GROUP BY resource_id
                    ) as counts
                    GROUP BY `range_label`
                    ORDER BY 
                        CASE `range_label`
                            WHEN "1-10" THEN 1
                            WHEN "11-25" THEN 2
                            WHEN "26-50" THEN 3
                            WHEN "51-100" THEN 4
                            WHEN "101-200" THEN 5
                            WHEN "201-400" THEN 6
                            WHEN "400+" THEN 7
                        END
                ');

                return [
                    'topDatasets' => $topDatasets->map(function ($row) {
                        return [
                            'id' => $row->id,
                            'identifier' => $row->identifier,
                            'title' => $row->title,
                            'count' => (int) $row->related_count,
                        ];
                    })->toArray(),
                    'distribution' => array_map(function ($row) {
                        return [
                            'range' => $row->range_label,
                            'count' => (int) $row->datasets,
                        ];
                    }, $distribution),
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
                    ->where('role', '!=', 'Creator') // Exclude Creator role (that's for authors)
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

    /**
     * Get identifier statistics (ROR, ORCID).
     *
     * @return array<string, mixed>
     */
    private function getIdentifierStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'identifiers',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Count affiliations with ROR (using identifier and identifiertype columns)
                $rorCount = $db->table('affiliation')
                    ->whereNotNull('identifier')
                    ->where(function ($query) {
                        $query->where('identifiertype', 'ROR')
                            ->orWhere('identifier', 'like', 'https://ror.org/%');
                    })
                    ->count();

                $totalAffiliations = $db->table('affiliation')->count();

                // Count resourceagents with ORCID (using identifier and identifiertype columns)
                $orcidCount = $db->table('resourceagent')
                    ->whereNotNull('identifier')
                    ->whereRaw('UPPER(identifiertype) = ?', ['ORCID'])
                    ->count();

                $totalAgents = $db->table('resourceagent')->count();

                return [
                    'ror' => [
                        'count' => $rorCount,
                        'total' => $totalAffiliations,
                        'percentage' => $totalAffiliations > 0 ? round(($rorCount / $totalAffiliations) * 100, 2) : 0,
                    ],
                    'orcid' => [
                        'count' => $orcidCount,
                        'total' => $totalAgents,
                        'percentage' => $totalAgents > 0 ? round(($orcidCount / $totalAgents) * 100, 2) : 0,
                    ],
                ];
            }
        );
    }

    /**
     * Get statistics for current year publications.
     *
     * @return array<string, mixed>
     */
    private function getCurrentYearStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'current_year',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);
                $currentYear = (int) date('Y');

                // Since we only have publicationyear (integer), we can't get monthly breakdown
                // We'll just return the total for the current year
                $totalCurrentYear = $db->table('resource')
                    ->where('publicationyear', $currentYear)
                    ->count();

                // Return empty monthly array since we can't determine months from integer year
                return [
                    'year' => $currentYear,
                    'total' => $totalCurrentYear,
                    'monthly' => [], // No monthly data available with integer year column
                ];
            }
        );
    }

    /**
     * Get affiliation statistics.
     *
     * @return array<string, mixed>
     */
    private function getAffiliationStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'affiliations',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Max affiliations per author/contributor
                $results = $db->select("
                    SELECT 
                        ra.resource_id,
                        ra.`order`,
                        COUNT(*) as affiliation_count
                    FROM resourceagent ra
                    LEFT JOIN affiliation a ON ra.resource_id = a.resourceagent_resource_id 
                        AND ra.`order` = a.resourceagent_order
                    WHERE a.resourceagent_resource_id IS NOT NULL
                    GROUP BY ra.resource_id, ra.`order`
                    ORDER BY affiliation_count DESC
                    LIMIT 1
                ");

                $maxAffiliations = !empty($results) ? (int) $results[0]->affiliation_count : 0;

                // Average affiliations per agent
                $avgResults = $db->select("
                    SELECT AVG(affiliation_count) as avg_affiliations
                    FROM (
                        SELECT 
                            ra.resource_id,
                            ra.`order`,
                            COUNT(*) as affiliation_count
                        FROM resourceagent ra
                        LEFT JOIN affiliation a ON ra.resource_id = a.resourceagent_resource_id 
                            AND ra.`order` = a.resourceagent_order
                        WHERE a.resourceagent_resource_id IS NOT NULL
                        GROUP BY ra.resource_id, ra.`order`
                    ) as sub
                ");

                $avgAffiliations = !empty($avgResults) && $avgResults[0]->avg_affiliations !== null 
                    ? round((float) $avgResults[0]->avg_affiliations, 2) 
                    : 0;

                return [
                    'max_per_agent' => $maxAffiliations,
                    'avg_per_agent' => $avgAffiliations,
                ];
            }
        );
    }

    /**
     * Get keyword statistics.
     *
     * @return array<string, mixed>
     */
    private function getKeywordStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'keywords',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // All keywords have a thesaurus in this database
                // Group by thesaurus to show distribution
                $byThesaurus = $db->table('thesauruskeyword')
                    ->select([
                        'thesaurus',
                        DB::raw('COUNT(DISTINCT keyword) as keyword_count'),
                        DB::raw('COUNT(DISTINCT resource_id) as dataset_count'),
                    ])
                    ->whereNotNull('thesaurus')
                    ->where('thesaurus', '!=', '')
                    ->groupBy('thesaurus')
                    ->orderBy('dataset_count', 'desc')
                    ->get();

                // Top 20 most used keywords overall
                $topKeywords = $db->table('thesauruskeyword')
                    ->select([
                        'keyword',
                        'thesaurus',
                        DB::raw('COUNT(DISTINCT resource_id) as count'),
                    ])
                    ->whereNotNull('keyword')
                    ->where('keyword', '!=', '')
                    ->groupBy('keyword', 'thesaurus')
                    ->orderBy('count', 'desc')
                    ->limit(20)
                    ->get();

                return [
                    'free' => [], // No free keywords in this database - all have thesaurus
                    'controlled' => $topKeywords->map(function ($row) {
                        return [
                            'keyword' => $row->keyword,
                            'count' => (int) $row->count,
                            'thesaurus' => $row->thesaurus,
                        ];
                    })->toArray(),
                    'by_thesaurus' => $byThesaurus->map(function ($row) {
                        return [
                            'thesaurus' => $row->thesaurus,
                            'keyword_count' => (int) $row->keyword_count,
                            'dataset_count' => (int) $row->dataset_count,
                        ];
                    })->toArray(),
                ];
            }
        );
    }

    /**
     * Get creation time statistics (by hour of day).
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCreationTimeStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'creation_time',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('resource')
                    ->select([
                        DB::raw('HOUR(created_at) as hour'),
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('created_at')
                    ->groupBy(DB::raw('HOUR(created_at)'))
                    ->orderBy('hour')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'hour' => (int) $row->hour,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }

    /**
     * Get description statistics.
     *
     * @return array<string, mixed>
     */
    private function getDescriptionStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'descriptions',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                // Count by type
                $byType = $db->table('description')
                    ->select([
                        'descriptiontype',
                        DB::raw('COUNT(DISTINCT resource_id) as count'),
                    ])
                    ->whereNotNull('descriptiontype')
                    ->groupBy('descriptiontype')
                    ->get();

                // Longest and shortest abstract (descriptiontype = 'Abstract')
                /** @var object{description: string, length: int}|null $longestResult */
                $longestResult = $db->table('description')
                    ->select([
                        'description',
                        DB::raw('LENGTH(description) as length'),
                    ])
                    ->where('descriptiontype', 'Abstract')
                    ->whereNotNull('description')
                    ->orderBy(DB::raw('LENGTH(description)'), 'desc')
                    ->limit(1)
                    ->first();

                /** @var object{description: string, length: int}|null $shortestResult */
                $shortestResult = $db->table('description')
                    ->select([
                        'description',
                        DB::raw('LENGTH(description) as length'),
                    ])
                    ->where('descriptiontype', 'Abstract')
                    ->whereNotNull('description')
                    ->where('description', '!=', '')
                    ->orderBy(DB::raw('LENGTH(description)'), 'asc')
                    ->limit(1)
                    ->first();

                return [
                    'by_type' => $byType->map(function ($row) {
                        return [
                            'type_id' => $row->descriptiontype, // Actually a string, not ID
                            'count' => (int) $row->count,
                        ];
                    })->toArray(),
                    'longest_abstract' => $longestResult !== null ? [
                        'length' => (int) $longestResult->length,
                        'preview' => mb_substr($longestResult->description, 0, 200),
                    ] : null,
                    'shortest_abstract' => $shortestResult !== null ? [
                        'length' => (int) $shortestResult->length,
                        'preview' => mb_substr($shortestResult->description, 0, 200),
                    ] : null,
                ];
            }
        );
    }

    /**
     * Get publication year distribution.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getPublicationYearStats(): array
    {
        return Cache::remember(
            self::CACHE_KEY_PREFIX . 'publication_years',
            self::CACHE_DURATION,
            function () {
                $db = DB::connection(self::DATASET_CONNECTION);

                $results = $db->table('resource')
                    ->select([
                        'publicationyear as year',
                        DB::raw('COUNT(*) as count'),
                    ])
                    ->whereNotNull('publicationyear')
                    ->where('publicationyear', '>', 0)
                    ->groupBy('publicationyear')
                    ->orderBy('publicationyear')
                    ->get();

                return $results->map(function ($row) {
                    return [
                        'year' => (int) $row->year,
                        'count' => (int) $row->count,
                    ];
                })->toArray();
            }
        );
    }
}
