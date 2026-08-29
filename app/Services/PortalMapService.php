<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class PortalMapService
{
    public function __construct(
        private readonly PortalSearchService $portalSearchService,
        private readonly PortalMapClusterService $clusterService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{north: float, south: float, east: float, west: float, width: int, height: int}  $viewport
     * @return array<string, mixed>
     */
    public function getMapData(array $filters, array $viewport, int $zoom, bool $includeExtent = false): array
    {
        $clustered = $this->clusterService->cluster(
            $this->visibleLocations($filters, $viewport),
            $viewport,
            $zoom,
        );

        $features = $this->hydrateResourceCandidates($clustered['features']);
        [$totalLocations, $extent] = $includeExtent
            ? $this->calculateExtent($filters)
            : [null, null];

        return [
            'schemaVersion' => 1,
            'features' => $features,
            'meta' => [
                ...$clustered['meta'],
                'returnedFeatures' => count($features),
                'totalLocations' => $totalLocations,
                'extent' => $extent,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{north: float, south: float, east: float, west: float, width: int, height: int}  $viewport
     * @return iterable<array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float}
     * }>
     */
    private function visibleLocations(array $filters, array $viewport): iterable
    {
        foreach ($this->locationQuery($filters, $viewport)->cursor() as $row) {
            $location = $this->normalizeLocation($row);

            if ($location !== null && $this->overlapsViewport($location['bounds'], $viewport)) {
                yield $location;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{north: float, south: float, east: float, west: float, width?: int, height?: int}|null  $viewport
     */
    private function locationQuery(array $filters, ?array $viewport): Builder
    {
        $resourceFilters = $filters;
        if ($viewport !== null && ($filters['bounds'] ?? null) === null) {
            $resourceFilters['bounds'] = [
                'north' => $viewport['north'],
                'south' => $viewport['south'],
                'east' => $viewport['east'],
                'west' => $viewport['west'],
            ];
        }

        $eligibleResources = $this->portalSearchService
            ->buildFilteredResourceQuery($resourceFilters, ($resourceFilters['bounds'] ?? null) !== null)
            ->select('resources.id');

        return DB::table('geo_locations as map_locations')
            ->joinSub($eligibleResources, 'eligible_resources', function ($join) {
                $join->on('eligible_resources.id', '=', 'map_locations.resource_id');
            })
            ->join('resources as map_resources', 'map_resources.id', '=', 'map_locations.resource_id')
            ->leftJoin('resource_types as map_resource_types', 'map_resource_types.id', '=', 'map_resources.resource_type_id')
            ->select([
                'map_locations.id as location_id',
                'map_locations.resource_id',
                'map_locations.geo_type',
                'map_locations.point_latitude',
                'map_locations.point_longitude',
                'map_locations.south_bound_latitude as box_south_latitude',
                'map_locations.west_bound_longitude as box_west_longitude',
                'map_locations.north_bound_latitude as box_north_latitude',
                'map_locations.east_bound_longitude as box_east_longitude',
                'map_locations.polygon_points',
                'map_locations.in_polygon_point_latitude',
                'map_locations.in_polygon_point_longitude',
                'map_resource_types.slug as resource_type_slug',
            ])
            ->where(function (Builder $query) {
                $query
                    ->whereNotNull('map_locations.point_latitude')
                    ->orWhereNotNull('map_locations.south_bound_latitude')
                    ->orWhereNotNull('map_locations.polygon_points')
                    ->orWhereNotNull('map_locations.in_polygon_point_latitude');
            })
            ->orderBy('map_locations.id');
    }

    /**
     * @return array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float}
     * }|null
     */
    private function normalizeLocation(object $row): ?array
    {
        $attributes = (array) $row;
        $polygonPoints = $this->decodePolygonPoints($attributes['polygon_points'] ?? null);
        $pointLatitude = $this->nullableFloat($attributes['point_latitude'] ?? null);
        $pointLongitude = $this->nullableFloat($attributes['point_longitude'] ?? null);
        $south = $this->nullableFloat($attributes['box_south_latitude'] ?? null);
        $west = $this->nullableFloat($attributes['box_west_longitude'] ?? null);
        $north = $this->nullableFloat($attributes['box_north_latitude'] ?? null);
        $east = $this->nullableFloat($attributes['box_east_longitude'] ?? null);
        $inPolygonLatitude = $this->nullableFloat($attributes['in_polygon_point_latitude'] ?? null);
        $inPolygonLongitude = $this->nullableFloat($attributes['in_polygon_point_longitude'] ?? null);
        $explicitType = strtolower((string) ($attributes['geo_type'] ?? ''));

        if ($this->isGlobalCoverageBox($south, $west, $north, $east)) {
            return null;
        }

        if ($explicitType === 'point' || ($pointLatitude !== null && $pointLongitude !== null)) {
            if ($pointLatitude === null || $pointLongitude === null) {
                return null;
            }

            return $this->normalizedResult($attributes, 'point', $pointLatitude, $pointLongitude, [
                'south' => $pointLatitude,
                'west' => $pointLongitude,
                'north' => $pointLatitude,
                'east' => $pointLongitude,
            ]);
        }

        if ($explicitType === 'box' || ($south !== null && $west !== null && $north !== null && $east !== null)) {
            if ($south === null || $west === null || $north === null || $east === null) {
                return null;
            }

            return $this->normalizedResult(
                $attributes,
                'box',
                ($south + $north) / 2,
                $this->longitudeMidpoint($west, $east),
                compact('south', 'west', 'north', 'east'),
            );
        }

        if (in_array($explicitType, ['polygon', 'line'], true) || count($polygonPoints) >= 2) {
            $minimumPoints = $explicitType === 'line' ? 2 : 3;

            if (count($polygonPoints) < $minimumPoints) {
                return null;
            }

            $latitudes = array_column($polygonPoints, 'latitude');
            $longitudes = array_column($polygonPoints, 'longitude');
            $geometryType = $explicitType === 'line' ? 'line' : 'polygon';
            $anchorLatitude = $inPolygonLatitude ?? array_sum($latitudes) / count($latitudes);
            $anchorLongitude = $inPolygonLongitude ?? array_sum($longitudes) / count($longitudes);

            return $this->normalizedResult($attributes, $geometryType, $anchorLatitude, $anchorLongitude, [
                'south' => min($latitudes),
                'west' => min($longitudes),
                'north' => max($latitudes),
                'east' => max($longitudes),
            ]);
        }

        if ($inPolygonLatitude !== null && $inPolygonLongitude !== null) {
            return $this->normalizedResult($attributes, 'point', $inPolygonLatitude, $inPolygonLongitude, [
                'south' => $inPolygonLatitude,
                'west' => $inPolygonLongitude,
                'north' => $inPolygonLatitude,
                'east' => $inPolygonLongitude,
            ]);
        }

        return null;
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}  $bounds
     * @param  array<string, mixed>  $attributes
     * @return array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float}
     * }
     */
    private function normalizedResult(
        array $attributes,
        string $geometryType,
        float $latitude,
        float $longitude,
        array $bounds,
    ): array {
        return [
            'location_id' => (int) ($attributes['location_id'] ?? 0),
            'resource_id' => (int) ($attributes['resource_id'] ?? 0),
            'resource_type_slug' => (string) ($attributes['resource_type_slug'] ?? 'other'),
            'geometry_type' => $geometryType,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'bounds' => $bounds,
        ];
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}  $geometry
     * @param  array{north: float, south: float, east: float, west: float, width?: int, height?: int}  $viewport
     */
    private function overlapsViewport(array $geometry, array $viewport): bool
    {
        if ($geometry['north'] < $viewport['south'] || $geometry['south'] > $viewport['north']) {
            return false;
        }

        foreach ($this->longitudeIntervals($geometry['west'], $geometry['east']) as $geometryInterval) {
            foreach ($this->longitudeIntervals($viewport['west'], $viewport['east']) as $viewportInterval) {
                if ($geometryInterval[1] >= $viewportInterval[0] && $geometryInterval[0] <= $viewportInterval[1]) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{0: float, 1: float}>
     */
    private function longitudeIntervals(float $west, float $east): array
    {
        return $west <= $east
            ? [[$west, $east]]
            : [[$west, 180.0], [-180.0, $east]];
    }

    private function isGlobalCoverageBox(?float $south, ?float $west, ?float $north, ?float $east): bool
    {
        if ($south === null || $west === null || $north === null || $east === null) {
            return false;
        }

        $tolerance = (float) config('app.geo.global_coverage_tolerance', GeoLocation::GLOBAL_COVERAGE_TOLERANCE);

        return abs($south + 90.0) <= $tolerance
            && abs($west + 180.0) <= $tolerance
            && abs($north - 90.0) <= $tolerance
            && abs($east - 180.0) <= $tolerance;
    }

    private function longitudeMidpoint(float $west, float $east): float
    {
        if ($west <= $east) {
            return ($west + $east) / 2;
        }

        $midpoint = ($west + ($east + 360.0)) / 2;

        return $midpoint > 180.0 ? $midpoint - 360.0 : $midpoint;
    }

    /**
     * @return list<array{latitude: float, longitude: float}>
     */
    private function decodePolygonPoints(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        $points = [];

        foreach ($value as $point) {
            if (! is_array($point)) {
                continue;
            }

            $latitude = $this->nullableFloat($point['latitude'] ?? $point['lat'] ?? null);
            $longitude = $this->nullableFloat($point['longitude'] ?? $point['lng'] ?? null);

            if ($latitude !== null && $longitude !== null) {
                $points[] = compact('latitude', 'longitude');
            }
        }

        return $points;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @param  list<array<string, mixed>>  $features
     * @return list<array<string, mixed>>
     */
    private function hydrateResourceCandidates(array $features): array
    {
        $locationIds = array_map(
            fn (array $feature): int => (int) $feature['locationId'],
            array_filter($features, fn (array $feature): bool => $feature['kind'] === 'resource-candidate'),
        );

        if ($locationIds === []) {
            return $features;
        }

        $locations = GeoLocation::query()
            ->whereIn('id', $locationIds)
            ->with([
                'resource.titles.titleType',
                'resource.creators.creatorable',
                'resource.resourceType',
                'resource.landingPage',
            ])
            ->get()
            ->keyBy('id');

        return array_map(function (array $feature) use ($locations): array {
            if ($feature['kind'] !== 'resource-candidate') {
                return $feature;
            }

            /** @var GeoLocation|null $location */
            $location = $locations->get($feature['locationId']);

            if ($location === null) {
                return [
                    'kind' => 'cluster',
                    'id' => 'missing-'.$feature['locationId'],
                    'position' => $feature['position'],
                    'bounds' => $feature['bounds'],
                    'count' => 1,
                    'resourceTypeCounts' => ['other' => 1],
                ];
            }

            return [
                'kind' => 'resource',
                'id' => (string) $location->id,
                'position' => $feature['position'],
                'bounds' => $feature['bounds'],
                'geometry' => $this->formatGeometry($location),
                'resource' => $this->formatResource($location->resource),
            ];
        }, $features);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatGeometry(GeoLocation $location): array
    {
        $type = strtolower((string) $location->geo_type);
        $pointLatitude = $this->nullableFloat($location->point_latitude);
        $pointLongitude = $this->nullableFloat($location->point_longitude);
        $south = $this->nullableFloat($location->south_bound_latitude);
        $west = $this->nullableFloat($location->west_bound_longitude);
        $north = $this->nullableFloat($location->north_bound_latitude);
        $east = $this->nullableFloat($location->east_bound_longitude);

        if ($type === 'point' || ($pointLatitude !== null && $pointLongitude !== null)) {
            return [
                'type' => 'point',
                'latitude' => $pointLatitude,
                'longitude' => $pointLongitude,
            ];
        }

        if ($type === 'box' || ($south !== null && $west !== null && $north !== null && $east !== null)) {
            return [
                'type' => 'box',
                'south' => $south,
                'west' => $west,
                'north' => $north,
                'east' => $east,
            ];
        }

        $points = $this->decodePolygonPoints($location->polygon_points);

        if ($points === []) {
            return [
                'type' => 'point',
                'latitude' => (float) $location->in_polygon_point_latitude,
                'longitude' => (float) $location->in_polygon_point_longitude,
            ];
        }

        return [
            'type' => $type === 'line' ? 'line' : 'polygon',
            'points' => $points,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatResource(Resource $resource): array
    {
        $mainTitle = $resource->titles->first(function ($title): bool {
            $slug = strtolower((string) $title->titleType?->slug);

            return in_array($slug, ['main-title', 'maintitle'], true);
        }) ?? $resource->titles->first();

        return [
            'id' => $resource->id,
            'identifier' => $resource->doi,
            'title' => $mainTitle->value ?? 'Untitled resource',
            'resourceType' => $resource->resourceType ? [
                'slug' => $resource->resourceType->slug,
                'name' => $resource->resourceType->name,
            ] : null,
            'creators' => $resource->creators
                ->sortBy('position')
                ->map(function ($creator): ?array {
                    $creatorable = $creator->getRelation('creatorable');

                    if (! $creatorable instanceof Person && ! $creatorable instanceof Institution) {
                        return null;
                    }

                    $name = $creatorable instanceof Person
                        ? $creatorable->full_name
                        : $creatorable->name;

                    return trim($name) !== '' ? ['name' => $name] : null;
                })
                ->filter()
                ->take(3)
                ->values()
                ->all(),
            'landingPageUrl' => $resource->landingPage?->public_url,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: int, 1: array{south: float, west: float, north: float, east: float}|null}
     */
    private function calculateExtent(array $filters): array
    {
        $total = 0;
        $extent = null;

        foreach ($this->locationQuery($filters, null)->cursor() as $row) {
            $location = $this->normalizeLocation($row);

            if ($location === null) {
                continue;
            }

            $total++;
            $bounds = $location['bounds'];
            $extent = $extent === null ? $bounds : [
                'south' => min($extent['south'], $bounds['south']),
                'west' => min($extent['west'], $bounds['west']),
                'north' => max($extent['north'], $bounds['north']),
                'east' => max($extent['east'], $bounds['east']),
            ];
        }

        return [$total, $extent];
    }
}
