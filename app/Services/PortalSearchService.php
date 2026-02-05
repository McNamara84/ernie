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
     *
     * @param  array{
     *     query?: string|null,
     *     type?: string|null,
     * }  $filters
     * @return \Illuminate\Database\Eloquent\Collection<int, Resource>
     */
    public function getMapData(array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        return $this->buildQuery($filters)
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
     * }  $filters
     * @return Builder<Resource>
     */
    private function buildQuery(array $filters): Builder
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
            ->orderByDesc('publication_year')
            ->orderByDesc('created_at');

        // Apply type filter
        $this->applyTypeFilter($query, $filters['type'] ?? null);

        // Apply search query
        $this->applySearchQuery($query, $filters['query'] ?? null);

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
