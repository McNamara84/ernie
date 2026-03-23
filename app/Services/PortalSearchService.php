<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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
                $crossesAntiMeridian = $bounds['west'] > $bounds['east'];

                // Point within bounding box
                $inner->where(function (Builder $point) use ($bounds, $crossesAntiMeridian): void {
                    $point->whereNotNull('point_latitude')
                        ->whereNotNull('point_longitude')
                        ->where('point_latitude', '>=', $bounds['south'])
                        ->where('point_latitude', '<=', $bounds['north']);

                    if ($crossesAntiMeridian) {
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
                ->orWhere(function (Builder $box) use ($bounds, $crossesAntiMeridian): void {
                    $box->whereNotNull('west_bound_longitude')
                        ->where('north_bound_latitude', '>=', $bounds['south'])
                        ->where('south_bound_latitude', '<=', $bounds['north']);

                    if ($crossesAntiMeridian) {
                        $box->where(function (Builder $lng) use ($bounds): void {
                            $lng->where('east_bound_longitude', '>=', $bounds['west'])
                                ->orWhere('west_bound_longitude', '<=', $bounds['east']);
                        });
                    } else {
                        $box->where('east_bound_longitude', '>=', $bounds['west'])
                            ->where('west_bound_longitude', '<=', $bounds['east']);
                    }
                });
            });
        });
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
