<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Support\CircularLongitudeCoverage;
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
     * @param  array{0: int, 1: array{south: float, west: float, north: float, east: float}|null}|null  $extentSummary
     * @return array<string, mixed>
     */
    public function getMapData(array $filters, array $viewport, int $zoom, ?array $extentSummary = null): array
    {
        $clustered = $this->clusterService->cluster(
            $this->visibleLocations($filters, $viewport),
            $viewport,
            $zoom,
        );

        $features = $this->hydrateResourceCandidates($clustered['features']);
        [$totalLocations, $extent] = $extentSummary ?? [null, null];

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

            if ($location === null || ! $this->overlapsViewport($location['bounds'], $viewport)) {
                continue;
            }

            $location = $this->anchorLocationToViewport($location, $viewport);
            if ($location === null) {
                continue;
            }

            unset($location['geometry_points']);
            yield $location;
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
        $geometryType = $this->resolveGeometryType(
            $explicitType,
            $pointLatitude,
            $pointLongitude,
            $south,
            $west,
            $north,
            $east,
            $polygonPoints,
            $inPolygonLatitude,
            $inPolygonLongitude,
        );

        if ($geometryType === 'box' && $this->isGlobalCoverageBox($south, $west, $north, $east)) {
            return null;
        }

        if ($geometryType === 'point') {
            if ($pointLatitude === null || $pointLongitude === null) {
                if ($inPolygonLatitude === null || $inPolygonLongitude === null) {
                    return null;
                }

                $pointLatitude = $inPolygonLatitude;
                $pointLongitude = $inPolygonLongitude;
            }

            return $this->normalizedResult($attributes, 'point', $pointLatitude, $pointLongitude, [
                'south' => $pointLatitude,
                'west' => $pointLongitude,
                'north' => $pointLatitude,
                'east' => $pointLongitude,
            ]);
        }

        if ($geometryType === 'box') {
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

        if (in_array($geometryType, ['polygon', 'line'], true)) {
            $minimumPoints = $geometryType === 'line' ? 2 : 3;

            if (count($polygonPoints) < $minimumPoints) {
                return null;
            }

            $latitudes = array_column($polygonPoints, 'latitude');
            $longitudes = array_column($polygonPoints, 'longitude');
            $anchorLatitude = $inPolygonLatitude ?? array_sum($latitudes) / count($latitudes);
            $anchorLongitude = $inPolygonLongitude ?? $this->circularLongitudeMean($longitudes);
            [$longitudeWest, $longitudeEast] = $this->minimalLongitudeBounds($longitudes);

            return $this->normalizedResult(
                $attributes,
                $geometryType,
                $anchorLatitude,
                $anchorLongitude,
                [
                    'south' => min($latitudes),
                    'west' => $longitudeWest,
                    'north' => max($latitudes),
                    'east' => $longitudeEast,
                ],
                $polygonPoints,
            );
        }

        return null;
    }

    /**
     * Respect an explicit DataCite geometry type before falling back to legacy
     * field inference. A geolocation may legitimately contain fields for more
     * than one geometry representation.
     *
     * @param  list<array{latitude: float, longitude: float}>  $polygonPoints
     */
    private function resolveGeometryType(
        string $explicitType,
        ?float $pointLatitude,
        ?float $pointLongitude,
        ?float $south,
        ?float $west,
        ?float $north,
        ?float $east,
        array $polygonPoints,
        ?float $inPolygonLatitude,
        ?float $inPolygonLongitude,
    ): ?string {
        if (in_array($explicitType, ['point', 'box', 'polygon', 'line'], true)) {
            return $explicitType;
        }

        if (count($polygonPoints) >= 3) {
            return 'polygon';
        }

        if ($south !== null && $west !== null && $north !== null && $east !== null) {
            return 'box';
        }

        if ($pointLatitude !== null && $pointLongitude !== null) {
            return 'point';
        }

        if ($inPolygonLatitude !== null && $inPolygonLongitude !== null) {
            return 'point';
        }

        return null;
    }

    /**
     * @param  array{south: float, west: float, north: float, east: float}  $bounds
     * @param  array<string, mixed>  $attributes
     * @param  list<array{latitude: float, longitude: float}>  $geometryPoints
     * @return array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float},
     *     geometry_points?: list<array{latitude: float, longitude: float}>
     * }
     */
    private function normalizedResult(
        array $attributes,
        string $geometryType,
        float $latitude,
        float $longitude,
        array $bounds,
        array $geometryPoints = [],
    ): array {
        $result = [
            'location_id' => (int) ($attributes['location_id'] ?? 0),
            'resource_id' => (int) ($attributes['resource_id'] ?? 0),
            'resource_type_slug' => (string) ($attributes['resource_type_slug'] ?? 'other'),
            'geometry_type' => $geometryType,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'bounds' => $bounds,
        ];

        if ($geometryPoints !== []) {
            $result['geometry_points'] = $geometryPoints;
        }

        return $result;
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
     * Move non-point clustering anchors into the actual visible part of their
     * geometry. This prevents a large intersecting shape from being clustered
     * at an off-screen centroid while shape details are still suppressed.
     *
     * @param  array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float},
     *     geometry_points?: list<array{latitude: float, longitude: float}>
     * }  $location
     * @param  array{north: float, south: float, east: float, west: float, width?: int, height?: int}  $viewport
     * @return array{
     *     location_id: int,
     *     resource_id: int,
     *     resource_type_slug: string,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float},
     *     geometry_points?: list<array{latitude: float, longitude: float}>
     * }|null
     */
    private function anchorLocationToViewport(array $location, array $viewport): ?array
    {
        if ($location['geometry_type'] === 'point') {
            return $location;
        }

        $visibleSouth = max($location['bounds']['south'], $viewport['south']);
        $visibleNorth = min($location['bounds']['north'], $viewport['north']);
        if ($visibleSouth > $visibleNorth) {
            return null;
        }

        if ($location['geometry_type'] === 'box') {
            $longitude = $this->longitudeIntersectionMidpoint(
                $location['bounds']['west'],
                $location['bounds']['east'],
                $viewport['west'],
                $viewport['east'],
            );

            if ($longitude === null) {
                return null;
            }

            $location['latitude'] = ($visibleSouth + $visibleNorth) / 2.0;
            $location['longitude'] = $longitude;

            return $location;
        }

        $points = $location['geometry_points'] ?? [];
        if ($points === []) {
            return null;
        }

        [$viewportWest, $viewportEast] = $this->unwrappedLongitudeRange($viewport['west'], $viewport['east']);
        $viewportCenter = ($viewportWest + $viewportEast) / 2.0;
        $unwrappedPoints = [];
        $previousLongitude = null;

        foreach ($points as $point) {
            $longitude = CircularLongitudeCoverage::nearestCopy(
                $point['longitude'],
                $previousLongitude ?? $viewportCenter,
            );
            $unwrappedPoints[] = ['latitude' => $point['latitude'], 'longitude' => $longitude];
            $previousLongitude = $longitude;
        }

        if ($location['geometry_type'] === 'polygon') {
            $anchorLongitude = CircularLongitudeCoverage::nearestCopy($location['longitude'], $viewportCenter);
            if ($this->pointInsideRectangle(
                $anchorLongitude,
                $location['latitude'],
                $viewportWest,
                $viewportEast,
                $viewport['south'],
                $viewport['north'],
            ) && $this->pointInPolygon($anchorLongitude, $location['latitude'], $unwrappedPoints)) {
                $location['longitude'] = CircularLongitudeCoverage::normalize($anchorLongitude);

                return $location;
            }
        }

        foreach ($unwrappedPoints as $point) {
            if ($this->pointInsideRectangle(
                $point['longitude'],
                $point['latitude'],
                $viewportWest,
                $viewportEast,
                $viewport['south'],
                $viewport['north'],
            )) {
                $location['latitude'] = $point['latitude'];
                $location['longitude'] = CircularLongitudeCoverage::normalize($point['longitude']);

                return $location;
            }
        }

        $segmentCount = count($unwrappedPoints) - 1;
        for ($index = 0; $index < $segmentCount; $index++) {
            $intersection = $this->clipSegmentToRectangle(
                $unwrappedPoints[$index],
                $unwrappedPoints[$index + 1],
                $viewportWest,
                $viewportEast,
                $viewport['south'],
                $viewport['north'],
            );

            if ($intersection !== null) {
                $location['latitude'] = $intersection['latitude'];
                $location['longitude'] = CircularLongitudeCoverage::normalize($intersection['longitude']);

                return $location;
            }
        }

        if ($location['geometry_type'] === 'polygon') {
            $closingIntersection = $this->clipSegmentToRectangle(
                $unwrappedPoints[count($unwrappedPoints) - 1],
                $unwrappedPoints[0],
                $viewportWest,
                $viewportEast,
                $viewport['south'],
                $viewport['north'],
            );

            if ($closingIntersection !== null) {
                $location['latitude'] = $closingIntersection['latitude'];
                $location['longitude'] = CircularLongitudeCoverage::normalize($closingIntersection['longitude']);

                return $location;
            }

            $viewportCandidates = [
                ['latitude' => ($viewport['south'] + $viewport['north']) / 2.0, 'longitude' => $viewportCenter],
                ['latitude' => $viewport['south'], 'longitude' => $viewportWest],
                ['latitude' => $viewport['south'], 'longitude' => $viewportEast],
                ['latitude' => $viewport['north'], 'longitude' => $viewportWest],
                ['latitude' => $viewport['north'], 'longitude' => $viewportEast],
            ];

            foreach ($viewportCandidates as $candidate) {
                if ($this->pointInPolygon($candidate['longitude'], $candidate['latitude'], $unwrappedPoints)) {
                    $location['latitude'] = $candidate['latitude'];
                    $location['longitude'] = CircularLongitudeCoverage::normalize($candidate['longitude']);

                    return $location;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function unwrappedLongitudeRange(float $west, float $east): array
    {
        return [$west, $west + CircularLongitudeCoverage::span($west, $east)];
    }

    private function longitudeIntersectionMidpoint(
        float $geometryWest,
        float $geometryEast,
        float $viewportWest,
        float $viewportEast,
    ): ?float {
        [$visibleWest, $visibleEast] = $this->unwrappedLongitudeRange($viewportWest, $viewportEast);
        $visibleCenter = ($visibleWest + $visibleEast) / 2.0;
        $geometrySpan = CircularLongitudeCoverage::span($geometryWest, $geometryEast);

        if ($geometrySpan >= 360.0 - 1.0E-9) {
            return CircularLongitudeCoverage::normalize($visibleCenter);
        }

        $baseWest = CircularLongitudeCoverage::nearestCopy($geometryWest, $visibleCenter);
        $bestIntersection = null;

        foreach ([-360.0, 0.0, 360.0] as $offset) {
            $candidateWest = $baseWest + $offset;
            $candidateEast = $candidateWest + $geometrySpan;
            $intersectionWest = max($candidateWest, $visibleWest);
            $intersectionEast = min($candidateEast, $visibleEast);

            if ($intersectionWest > $intersectionEast) {
                continue;
            }

            $width = $intersectionEast - $intersectionWest;
            $midpoint = ($intersectionWest + $intersectionEast) / 2.0;
            $distance = abs($midpoint - $visibleCenter);

            if ($bestIntersection === null
                || $width > $bestIntersection['width']
                || ($width === $bestIntersection['width'] && $distance < $bestIntersection['distance'])) {
                $bestIntersection = compact('width', 'midpoint', 'distance');
            }
        }

        return $bestIntersection === null
            ? null
            : CircularLongitudeCoverage::normalize($bestIntersection['midpoint']);
    }

    private function pointInsideRectangle(
        float $longitude,
        float $latitude,
        float $west,
        float $east,
        float $south,
        float $north,
    ): bool {
        return $longitude >= $west && $longitude <= $east
            && $latitude >= $south && $latitude <= $north;
    }

    /**
     * @param  list<array{latitude: float, longitude: float}>  $polygon
     */
    private function pointInPolygon(float $longitude, float $latitude, array $polygon): bool
    {
        $inside = false;
        $previousIndex = count($polygon) - 1;

        foreach ($polygon as $index => $point) {
            $previous = $polygon[$previousIndex];
            $crossesLatitude = ($point['latitude'] > $latitude) !== ($previous['latitude'] > $latitude);

            if ($crossesLatitude) {
                $intersectionLongitude = (($previous['longitude'] - $point['longitude'])
                    * ($latitude - $point['latitude'])
                    / ($previous['latitude'] - $point['latitude'])) + $point['longitude'];

                if ($longitude < $intersectionLongitude) {
                    $inside = ! $inside;
                }
            }

            $previousIndex = $index;
        }

        return $inside;
    }

    /**
     * @param  array{latitude: float, longitude: float}  $start
     * @param  array{latitude: float, longitude: float}  $end
     * @return array{latitude: float, longitude: float}|null
     */
    private function clipSegmentToRectangle(
        array $start,
        array $end,
        float $west,
        float $east,
        float $south,
        float $north,
    ): ?array {
        $deltaLongitude = $end['longitude'] - $start['longitude'];
        $deltaLatitude = $end['latitude'] - $start['latitude'];
        $minimum = 0.0;
        $maximum = 1.0;
        $constraints = [
            [-$deltaLongitude, $start['longitude'] - $west],
            [$deltaLongitude, $east - $start['longitude']],
            [-$deltaLatitude, $start['latitude'] - $south],
            [$deltaLatitude, $north - $start['latitude']],
        ];

        foreach ($constraints as [$direction, $distance]) {
            if (abs($direction) < 1.0E-12) {
                if ($distance < 0.0) {
                    return null;
                }

                continue;
            }

            $ratio = $distance / $direction;
            if ($direction < 0.0) {
                $minimum = max($minimum, $ratio);
            } else {
                $maximum = min($maximum, $ratio);
            }

            if ($minimum > $maximum) {
                return null;
            }
        }

        $midpoint = ($minimum + $maximum) / 2.0;

        return [
            'latitude' => $start['latitude'] + ($midpoint * $deltaLatitude),
            'longitude' => $start['longitude'] + ($midpoint * $deltaLongitude),
        ];
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
        return CircularLongitudeCoverage::midpoint($west, $east);
    }

    /**
     * @param  list<float>  $longitudes
     */
    private function circularLongitudeMean(array $longitudes): float
    {
        $sine = 0.0;
        $cosine = 0.0;

        foreach ($longitudes as $longitude) {
            $radians = deg2rad($longitude);
            $sine += sin($radians);
            $cosine += cos($radians);
        }

        if (abs($sine) < 1.0E-12 && abs($cosine) < 1.0E-12) {
            return CircularLongitudeCoverage::normalize($longitudes[0] ?? 0.0);
        }

        return CircularLongitudeCoverage::normalize(rad2deg(atan2($sine, $cosine)));
    }

    /**
     * Return the shortest longitude interval containing every point. A west
     * value greater than east represents an antimeridian-crossing interval.
     *
     * @param  list<float>  $longitudes
     * @return array{0: float, 1: float}
     */
    private function minimalLongitudeBounds(array $longitudes): array
    {
        $coverage = new CircularLongitudeCoverage;
        foreach ($longitudes as $longitude) {
            $coverage->add($longitude, $longitude);
        }

        $bounds = $coverage->bounds();

        return $bounds === null ? [0.0, 0.0] : [$bounds['west'], $bounds['east']];
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
        $explicitType = strtolower((string) $location->geo_type);
        $pointLatitude = $this->nullableFloat($location->point_latitude);
        $pointLongitude = $this->nullableFloat($location->point_longitude);
        $south = $this->nullableFloat($location->south_bound_latitude);
        $west = $this->nullableFloat($location->west_bound_longitude);
        $north = $this->nullableFloat($location->north_bound_latitude);
        $east = $this->nullableFloat($location->east_bound_longitude);
        $points = $this->decodePolygonPoints($location->polygon_points);
        $inPolygonLatitude = $this->nullableFloat($location->in_polygon_point_latitude);
        $inPolygonLongitude = $this->nullableFloat($location->in_polygon_point_longitude);
        $type = $this->resolveGeometryType(
            $explicitType,
            $pointLatitude,
            $pointLongitude,
            $south,
            $west,
            $north,
            $east,
            $points,
            $inPolygonLatitude,
            $inPolygonLongitude,
        );

        if ($type === 'point') {
            return [
                'type' => 'point',
                'latitude' => $pointLatitude ?? $inPolygonLatitude,
                'longitude' => $pointLongitude ?? $inPolygonLongitude,
            ];
        }

        if ($type === 'box') {
            return [
                'type' => 'box',
                'south' => $south,
                'west' => $west,
                'north' => $north,
                'east' => $east,
            ];
        }

        if ($points === []) {
            return [
                'type' => 'point',
                'latitude' => $inPolygonLatitude,
                'longitude' => $inPolygonLongitude,
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
    public function calculateExtent(array $filters): array
    {
        $total = 0;
        $south = null;
        $north = null;
        $longitudeCoverage = new CircularLongitudeCoverage;

        foreach ($this->locationQuery($filters, null)->cursor() as $row) {
            $location = $this->normalizeLocation($row);

            if ($location === null) {
                continue;
            }

            $total++;
            $bounds = $location['bounds'];
            $south = $south === null ? $bounds['south'] : min($south, $bounds['south']);
            $north = $north === null ? $bounds['north'] : max($north, $bounds['north']);
            $longitudeCoverage->add($bounds['west'], $bounds['east']);
        }

        $longitudeBounds = $longitudeCoverage->bounds();
        if ($south === null || $north === null || $longitudeBounds === null) {
            return [$total, null];
        }

        return [$total, [
            'south' => $south,
            'west' => $longitudeBounds['west'],
            'north' => $north,
            'east' => $longitudeBounds['east'],
        ]];
    }
}
