<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\DateType;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Service for searching and filtering resources in the public portal.
 *
 * Provides full-text search, type filtering, and pagination for the
 * publicly accessible portal page at /portal.
 */
class PortalSearchService
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 50;

    /**
     * Search resources with optional filters.
     *
     * @param  array{
     *     query?: string|null,
     *     type?: string|null,
     *     keywords?: string[]|null,
     *     bounds?: array{north: float, south: float, east: float, west: float}|null,
     *     temporal?: array{dateType: string, yearFrom: int, yearTo: int}|null,
     *     page?: int,
     *     per_page?: int,
     * }  $filters
     * @return LengthAwarePaginator<int, Resource>
     */
    public function search(array $filters = []): LengthAwarePaginator
    {
        $query = $this->buildQuery($filters);

        $perPage = min(
            $filters['per_page'] ?? self::DEFAULT_PER_PAGE,
            self::MAX_PER_PAGE
        );

        /** @var LengthAwarePaginator<int, Resource> */
        return $query->paginate($perPage);
    }

    /**
     * Get all resources with geo locations for map display.
     *
     * Returns a simplified dataset optimized for map rendering,
     * including only resources that have at least one geo location.
     * Bounds filter is intentionally NOT applied so all markers remain
     * visible on the map for spatial context.
     *
     * @param  array{
     *     query?: string|null,
     *     type?: string|null,
     *     keywords?: string[]|null,
     *     bounds?: array{north: float, south: float, east: float, west: float}|null,
     *     temporal?: array{dateType: string, yearFrom: int, yearTo: int}|null,
     * }  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Resource>
     */
    public function getMapData(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->buildQuery($filters, applyBounds: false)
            ->whereHas('geoLocations')
            ->with(['geoLocations', 'titles.titleType', 'creators.creatorable', 'resourceType'])
            ->get();
    }

    /**
     * Build the base query with filters applied.
     *
     * @param  array{
     *     query?: string|null,
     *     type?: string|null,
     *     keywords?: string[]|null,
     *     bounds?: array{north: float, south: float, east: float, west: float}|null,
     *     temporal?: array{dateType: string, yearFrom: int, yearTo: int}|null,
     * }  $filters
     * @return Builder<Resource>
     */
    private function buildQuery(array $filters, bool $applyBounds = true): Builder
    {
        $query = Resource::query()
            ->with([
                'titles.titleType',
                'creators.creatorable',
                'resourceType',
                'geoLocations',
                'landingPage',
            ])
            ->whereHas('landingPage', function (Builder $q): void {
                $q->where('is_published', true);
            })
            // Order by actual publication date (when landing page was published)
            // Then by resource creation date as fallback
            ->orderByDesc(
                \App\Models\LandingPage::select('published_at')
                    ->whereColumn('landing_pages.resource_id', 'resources.id')
                    ->limit(1)
            )
            ->orderByDesc('created_at');

        // Apply type filter
        $this->applyTypeFilter($query, $filters['type'] ?? null);

        // Apply search query
        $this->applySearchQuery($query, $filters['query'] ?? null);

        // Apply keyword filter
        $this->applyKeywordFilter($query, $filters['keywords'] ?? null);

        // Apply temporal date filter
        $this->applyTemporalFilter($query, $filters['temporal'] ?? null);

        // Apply spatial bounds filter (skipped for map data to keep all markers visible)
        if ($applyBounds) {
            $this->applyBoundsFilter($query, $filters['bounds'] ?? null);
        }

        return $query;
    }

    /**
     * Apply resource type filter (all, doi, igsn).
     *
     * @param  Builder<Resource>  $query
     */
    private function applyTypeFilter(Builder $query, ?string $type): void
    {
        if ($type === null || $type === 'all') {
            return;
        }

        if ($type === 'igsn') {
            // IGSN resources have resource_type = PhysicalObject
            $query->igsns();
        } elseif ($type === 'doi') {
            // Regular DOI resources (not IGSNs)
            $query->whereDoesntHave('resourceType', function (Builder $q): void {
                $q->where('slug', 'physical-object');
            });
        }
    }

    /**
     * Apply full-text search across multiple fields.
     *
     * @param  Builder<Resource>  $query
     */
    private function applySearchQuery(Builder $query, ?string $searchQuery): void
    {
        if ($searchQuery === null || trim($searchQuery) === '') {
            return;
        }

        $searchTerm = '%' . trim($searchQuery) . '%';

        $query->where(function (Builder $q) use ($searchTerm): void {
            // Search in DOI
            $q->where('doi', 'like', $searchTerm)
                // Search in titles
                ->orWhereHas('titles', function (Builder $titleQuery) use ($searchTerm): void {
                    $titleQuery->where('value', 'like', $searchTerm);
                })
                // Search in descriptions
                ->orWhereHas('descriptions', function (Builder $descQuery) use ($searchTerm): void {
                    $descQuery->where('value', 'like', $searchTerm);
                })
                // Search in creator names (persons)
                ->orWhereHas('creators', function (Builder $creatorQuery) use ($searchTerm): void {
                    $creatorQuery->whereHasMorph(
                        'creatorable',
                        [Person::class],
                        function (Builder $personQuery) use ($searchTerm): void {
                            $personQuery->where('family_name', 'like', $searchTerm)
                                ->orWhere('given_name', 'like', $searchTerm);
                        }
                    );
                })
                // Search in creator names (institutions)
                ->orWhereHas('creators', function (Builder $creatorQuery) use ($searchTerm): void {
                    $creatorQuery->whereHasMorph(
                        'creatorable',
                        [Institution::class],
                        function (Builder $instQuery) use ($searchTerm): void {
                            $instQuery->where('name', 'like', $searchTerm);
                        }
                    );
                })
                // Search in subjects/keywords
                ->orWhereHas('subjects', function (Builder $subjectQuery) use ($searchTerm): void {
                    $subjectQuery->where('value', 'like', $searchTerm);
                });
        });
    }

    /**
     * Apply keyword filter with AND logic.
     *
     * Each keyword adds a whereHas constraint, so the resource
     * must have ALL specified keywords to match.
     *
     * @param  Builder<Resource>  $query
     * @param  string[]|null  $keywords
     */
    private function applyKeywordFilter(Builder $query, ?array $keywords): void
    {
        if ($keywords === null || $keywords === []) {
            return;
        }

        foreach ($keywords as $keyword) {
            $keyword = trim($keyword);
            if ($keyword === '') {
                continue;
            }

            $query->whereHas('subjects', function (Builder $q) use ($keyword): void {
                $q->where('value', $keyword);
            });
        }
    }

    /**
     * Apply temporal date filter.
     *
     * Matches resources that have a date of the specified type whose year
     * overlaps the requested [yearFrom, yearTo] range. Handles single dates,
     * closed ranges, and open-ended ranges.
     *
     * @param  Builder<Resource>  $query
     * @param  array{dateType: string, yearFrom: int, yearTo: int}|null  $temporal
     */
    private function applyTemporalFilter(Builder $query, ?array $temporal): void
    {
        if ($temporal === null) {
            return;
        }

        $slug = $temporal['dateType'];
        $yearFrom = $temporal['yearFrom'];
        $yearTo = $temporal['yearTo'];

        $dvYear = $this->yearExpression('date_value');
        $sdYear = $this->yearExpression('start_date');
        $edYear = $this->yearExpression('end_date');

        $query->whereHas('dates', function (Builder $q) use ($slug, $yearFrom, $yearTo, $dvYear, $sdYear, $edYear): void {
            $q->whereHas('dateType', fn (Builder $dt): Builder => $dt->where('slug', $slug));

            $q->where(function (Builder $dateQ) use ($yearFrom, $yearTo, $dvYear, $sdYear, $edYear): void {
                // Case 1: Single date (date_value) – year within range
                $dateQ->where(function (Builder $single) use ($yearFrom, $yearTo, $dvYear): void {
                    $single->whereNotNull('date_value')
                        ->whereRaw("{$dvYear} >= ?", [$yearFrom])
                        ->whereRaw("{$dvYear} <= ?", [$yearTo]);
                })
                // Case 2: Date range (start_date/end_date) – overlap check
                ->orWhere(function (Builder $range) use ($yearFrom, $yearTo, $sdYear, $edYear): void {
                    $range->whereNotNull('start_date')
                        ->whereRaw("{$sdYear} <= ?", [$yearTo])
                        ->where(function (Builder $endCheck) use ($yearFrom, $edYear): void {
                            $endCheck->whereRaw("{$edYear} >= ?", [$yearFrom])
                                ->orWhereNull('end_date');
                        });
                });
            });
        });
    }

    /**
     * Get the available year ranges for temporal filtering.
     *
     * Returns min/max years for each active date type that has data
     * in published resources. Results are cached for 1 hour.
     *
     * @return array<string, array{min: int, max: int}>
     */
    public function getTemporalRange(): array
    {
        $cacheKey = CacheKey::PORTAL_TEMPORAL_RANGE;

        /** @var array<string, array{min: int, max: int}> */
        return Cache::remember($cacheKey->key(), $cacheKey->ttl(), function (): array {
            $slugs = ['Created', 'Collected', 'Coverage'];

            $activeSlugs = DateType::query()
                ->where('is_active', true)
                ->whereIn('slug', $slugs)
                ->pluck('slug')
                ->all();

            if ($activeSlugs === []) {
                return [];
            }

            // Query min/max years across date_value, start_date, and end_date
            // Only for resources with published landing pages
            $isSqlite = DB::getDriverName() === 'sqlite';

            // SQLite: MIN/MAX with multiple args act as scalar LEAST/GREATEST
            // MySQL: requires dedicated LEAST/GREATEST functions
            $dvYear = $this->yearExpression('dates.date_value');
            $sdYear = $this->yearExpression('dates.start_date');
            $edYear = $this->yearExpression('dates.end_date');

            // Fallback for open-ended ranges (NULL end_date) – treat as current year
            // so the slider range aligns with applyTemporalFilter() semantics.
            // Only applies when start_date is set (true date range), not for single dates.
            /** @var literal-string $currentYearFallback */
            $currentYearFallback = (string) date('Y');
            /** @var literal-string $openEndedMax */
            $openEndedMax = "CASE WHEN dates.start_date IS NOT NULL AND dates.end_date IS NULL THEN {$currentYearFallback} ELSE COALESCE({$edYear}, 0) END";

            if ($isSqlite) {
                $minYearExpr = "MIN(MIN(COALESCE({$dvYear}, 9999), COALESCE({$sdYear}, 9999), COALESCE({$edYear}, 9999))) as min_year";
                $maxYearExpr = "MAX(MAX(COALESCE({$dvYear}, 0), COALESCE({$sdYear}, 0), {$openEndedMax})) as max_year";
            } else {
                $minYearExpr = "MIN(LEAST(COALESCE({$dvYear}, 9999), COALESCE({$sdYear}, 9999), COALESCE({$edYear}, 9999))) as min_year";
                $maxYearExpr = "MAX(GREATEST(COALESCE({$dvYear}, 0), COALESCE({$sdYear}, 0), {$openEndedMax})) as max_year";
            }

            $results = DB::table('dates')
                ->join('date_types', 'dates.date_type_id', '=', 'date_types.id')
                ->join('resources', 'dates.resource_id', '=', 'resources.id')
                ->join('landing_pages', 'landing_pages.resource_id', '=', 'resources.id')
                ->where('landing_pages.is_published', true)
                ->whereIn('date_types.slug', $activeSlugs)
                ->select(
                    'date_types.slug',
                    DB::raw($minYearExpr),
                    DB::raw($maxYearExpr),
                )
                ->groupBy('date_types.slug')
                ->orderBy('date_types.slug')
                ->get();

            $ranges = [];
            foreach ($results as $row) {
                $minYear = (int) $row->min_year;
                $maxYear = (int) $row->max_year;

                if ($minYear > 0 && $maxYear > 0 && $minYear <= $maxYear) {
                    $ranges[$row->slug] = [
                        'min' => $minYear,
                        'max' => $maxYear,
                    ];
                }
            }

            return $ranges;
        });
    }

    /**
     * Build a cross-database SQL expression that extracts the year from a date column.
     *
     * Handles variable-granularity date strings (YYYY, YYYY-MM, YYYY-MM-DD).
     * SQLite uses SUBSTR, MySQL/MariaDB use LEFT with UNSIGNED, PostgreSQL uses
     * LEFT with INTEGER.
     *
     * @param  literal-string  $column
     * @return literal-string
     */
    private function yearExpression(string $column): string
    {
        return match (DB::getDriverName()) {
            'sqlite' => "CAST(SUBSTR({$column}, 1, 4) AS INTEGER)",
            'pgsql' => "CAST(LEFT({$column}, 4) AS INTEGER)",
            'mysql', 'mariadb' => "CAST(LEFT({$column}, 4) AS UNSIGNED)",
            default => throw new \RuntimeException('Unsupported database driver: ' . DB::getDriverName()),
        };
    }

    /**
     * Apply spatial bounding box filter with intersects logic.
     *
     * A resource matches if ANY of its geo locations intersects the
     * search bounding box. Handles points (within bbox), bounding boxes
     * (rectangle overlap), and anti-meridian crossing.
     *
     * @param  Builder<Resource>  $query
     * @param  array{north: float, south: float, east: float, west: float}|null  $bounds
     */
    private function applyBoundsFilter(Builder $query, ?array $bounds): void
    {
        if ($bounds === null) {
            return;
        }

        $query->whereHas('geoLocations', function (Builder $q) use ($bounds): void {
            $q->where(function (Builder $inner) use ($bounds): void {
                $searchCrossesAM = $bounds['west'] > $bounds['east'];

                // Point within bounding box
                $inner->where(function (Builder $point) use ($bounds, $searchCrossesAM): void {
                    $point->whereNotNull('point_latitude')
                        ->whereNotNull('point_longitude')
                        ->where('point_latitude', '>=', $bounds['south'])
                        ->where('point_latitude', '<=', $bounds['north']);

                    if ($searchCrossesAM) {
                        $point->where(function (Builder $lng) use ($bounds): void {
                            $lng->where('point_longitude', '>=', $bounds['west'])
                                ->orWhere('point_longitude', '<=', $bounds['east']);
                        });
                    } else {
                        $point->where('point_longitude', '>=', $bounds['west'])
                            ->where('point_longitude', '<=', $bounds['east']);
                    }
                })
                // Bounding box overlaps (rectangle intersection)
                ->orWhere(function (Builder $box) use ($bounds, $searchCrossesAM): void {
                    $box->whereNotNull('west_bound_longitude')
                        ->where('north_bound_latitude', '>=', $bounds['south'])
                        ->where('south_bound_latitude', '<=', $bounds['north']);

                    // Longitude overlap must account for both the search bounds
                    // and the stored box potentially crossing the anti-meridian.
                    // A stored box crosses the AM when west_bound > east_bound.
                    $box->where(function (Builder $lng) use ($bounds, $searchCrossesAM): void {
                        if ($searchCrossesAM) {
                            // Search bounds cross AM: stored box overlaps if its east >= search west OR its west <= search east
                            // But we must also handle stored box crossing AM
                            $lng->where(function (Builder $storedNormal) use ($bounds): void {
                                // Stored box does NOT cross AM (west <= east)
                                $storedNormal->whereColumn('west_bound_longitude', '<=', 'east_bound_longitude')
                                    ->where(function (Builder $overlap) use ($bounds): void {
                                        $overlap->where('east_bound_longitude', '>=', $bounds['west'])
                                            ->orWhere('west_bound_longitude', '<=', $bounds['east']);
                                    });
                            })->orWhere(function (Builder $storedCrossing): void {
                                // Stored box ALSO crosses AM — always overlaps in longitude
                                $storedCrossing->whereColumn('west_bound_longitude', '>', 'east_bound_longitude');
                            });
                        } else {
                            // Search bounds do NOT cross AM
                            $lng->where(function (Builder $storedNormal) use ($bounds): void {
                                // Stored box does NOT cross AM: standard overlap check
                                $storedNormal->whereColumn('west_bound_longitude', '<=', 'east_bound_longitude')
                                    ->where('east_bound_longitude', '>=', $bounds['west'])
                                    ->where('west_bound_longitude', '<=', $bounds['east']);
                            })->orWhere(function (Builder $storedCrossing) use ($bounds): void {
                                // Stored box crosses AM: overlaps if its east >= search west OR its west <= search east
                                // (stored interval wraps around, so it covers [stored_west, 180] ∪ [-180, stored_east])
                                $storedCrossing->whereColumn('west_bound_longitude', '>', 'east_bound_longitude')
                                    ->where(function (Builder $overlap) use ($bounds): void {
                                        $overlap->where('west_bound_longitude', '<=', $bounds['east'])
                                            ->orWhere('east_bound_longitude', '>=', $bounds['west']);
                                    });
                            });
                        }
                    });
                })
                // Polygon in_polygon_point within bounding box
                ->orWhere(function (Builder $poly) use ($bounds, $searchCrossesAM): void {
                    $poly->whereNotNull('polygon_points')
                        ->whereNotNull('in_polygon_point_latitude')
                        ->whereNotNull('in_polygon_point_longitude')
                        ->where('in_polygon_point_latitude', '>=', $bounds['south'])
                        ->where('in_polygon_point_latitude', '<=', $bounds['north']);

                    if ($searchCrossesAM) {
                        $poly->where(function (Builder $lng) use ($bounds): void {
                            $lng->where('in_polygon_point_longitude', '>=', $bounds['west'])
                                ->orWhere('in_polygon_point_longitude', '<=', $bounds['east']);
                        });
                    } else {
                        $poly->where('in_polygon_point_longitude', '>=', $bounds['west'])
                            ->where('in_polygon_point_longitude', '<=', $bounds['east']);
                    }
                })
                // Polygon without in_polygon_point: use bounding box of polygon vertices.
                // Check if the polygon's bounding box overlaps the search bounds by
                // extracting min/max lat/lng from the JSON polygon_points array.
                ->orWhere(function (Builder $polyBbox) use ($bounds, $searchCrossesAM): void {
                    $polyBbox->whereNotNull('polygon_points')
                        ->whereNull('in_polygon_point_latitude');

                    $this->applyPolygonBboxOverlap($polyBbox, $bounds, $searchCrossesAM);
                });
            });
        });
    }

    /**
     * Apply polygon bounding box overlap check using JSON extraction.
     *
     * Since polygon_points is stored as a JSON array, we extract the
     * min/max coordinates directly in SQL. Falls back to matching all
     * polygon rows on SQLite (which lacks JSON_TABLE).
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  array{north: float, south: float, east: float, west: float}  $bounds
     */
    private function applyPolygonBboxOverlap(Builder $query, array $bounds, bool $searchCrossesAM): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // MySQL 8+: Extract min/max from JSON array using JSON_TABLE
            // The polygon's bounding box overlaps the search box when:
            // maxLat >= search.south AND minLat <= search.north AND lng overlap
            $query->whereRaw(
                '(SELECT MAX(pt.lat) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lat DOUBLE PATH \'$.latitude\')) AS pt) >= ?',
                [$bounds['south']]
            )->whereRaw(
                '(SELECT MIN(pt.lat) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lat DOUBLE PATH \'$.latitude\')) AS pt) <= ?',
                [$bounds['north']]
            );

            if ($searchCrossesAM) {
                $query->where(function (Builder $lng) use ($bounds): void {
                    $lng->whereRaw(
                        '(SELECT MAX(pt.lng) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lng DOUBLE PATH \'$.longitude\')) AS pt) >= ?',
                        [$bounds['west']]
                    )->orWhereRaw(
                        '(SELECT MIN(pt.lng) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lng DOUBLE PATH \'$.longitude\')) AS pt) <= ?',
                        [$bounds['east']]
                    );
                });
            } else {
                $query->whereRaw(
                    '(SELECT MAX(pt.lng) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lng DOUBLE PATH \'$.longitude\')) AS pt) >= ?',
                    [$bounds['west']]
                )->whereRaw(
                    '(SELECT MIN(pt.lng) FROM JSON_TABLE(polygon_points, \'$[*]\' COLUMNS(lng DOUBLE PATH \'$.longitude\')) AS pt) <= ?',
                    [$bounds['east']]
                );
            }
        } else {
            // SQLite fallback: JSON_TABLE not available.
            // Match any geo_location row that has polygon_points.
            // This is a conservative over-match that will include polygons
            // outside the search bounds, but avoids false negatives.
            // Acceptable because spatial filter is a UI convenience, not a strict API.
        }
    }

    /**
     * Transform a resource for portal display.
     *
     * @return array<string, mixed>
     */
    public function transformForPortal(Resource $resource): array
    {
        $mainTitle = $this->getMainTitle($resource);
        $creators = $this->formatCreators($resource);
        $geoLocations = $this->formatGeoLocations($resource);

        $resourceType = $resource->resourceType;

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'title' => $mainTitle,
            'creators' => $creators,
            'year' => $resource->publication_year,
            'resourceType' => $resourceType !== null ? $resourceType->name : 'Unknown',
            'resourceTypeSlug' => $resourceType?->slug,
            'isIgsn' => $resourceType?->slug === 'physical-object',
            'geoLocations' => $geoLocations,
            'landingPageUrl' => $resource->landingPage?->public_url,
        ];
    }

    /**
     * Get the main title from a resource.
     */
    private function getMainTitle(Resource $resource): string
    {
        $mainTitle = $resource->titles
            ->first(fn (Title $t): bool => $t->titleType?->slug === 'main-title');

        if ($mainTitle !== null) {
            return $mainTitle->value;
        }

        $firstTitle = $resource->titles->first();

        if ($firstTitle !== null) {
            return $firstTitle->value;
        }

        return 'Untitled';
    }

    /**
     * Format creators for portal display.
     *
     * @return array<int, array{name: string, givenName: string|null}>
     */
    private function formatCreators(Resource $resource): array
    {
        return $resource->creators
            ->sortBy('position')
            ->map(function (ResourceCreator $creator): array {
                $creatorable = $creator->creatorable;

                if ($creatorable instanceof Person) {
                    return [
                        'name' => $creatorable->family_name ?? 'Unknown',
                        'givenName' => $creatorable->given_name,
                    ];
                }

                // creatorable is Institution
                /** @var Institution $creatorable */
                return [
                    'name' => $creatorable->name,
                    'givenName' => null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Format geo locations for portal display.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatGeoLocations(Resource $resource): array
    {
        return $resource->geoLocations->map(function (GeoLocation $geo): array {
            return [
                'id' => $geo->id,
                'type' => $this->determineGeoType($geo),
                'point' => $geo->point_latitude !== null && $geo->point_longitude !== null
                    ? ['lat' => (float) $geo->point_latitude, 'lng' => (float) $geo->point_longitude]
                    : null,
                'bounds' => $geo->west_bound_longitude !== null
                    ? [
                        'north' => (float) $geo->north_bound_latitude,
                        'south' => (float) $geo->south_bound_latitude,
                        'east' => (float) $geo->east_bound_longitude,
                        'west' => (float) $geo->west_bound_longitude,
                    ]
                    : null,
                'polygon' => $geo->polygon_points !== null
                    ? array_map(fn (array $p): array => ['lat' => $p['latitude'], 'lng' => $p['longitude']], $geo->polygon_points)
                    : null,
            ];
        })->all();
    }

    /**
     * Determine the geo location type.
     */
    private function determineGeoType(GeoLocation $geo): string
    {
        // Prefer explicit geo_type column
        if ($geo->geo_type !== null) {
            return $geo->geo_type;
        }

        // Fall back to implicit detection for legacy rows
        if ($geo->polygon_points !== null && count($geo->polygon_points) >= 3) {
            return 'polygon';
        }

        if ($geo->west_bound_longitude !== null) {
            return 'box';
        }

        if ($geo->point_latitude !== null && $geo->point_longitude !== null) {
            return 'point';
        }

        return 'unknown';
    }
}
