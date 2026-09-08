<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\CircularLongitudeCoverage;

/**
 * Bounded, database-independent clustering for public portal map locations.
 *
 * Input rows are scalar projections produced by PortalMapService. No Eloquent
 * models are retained, so memory usage follows visible grid cells rather than
 * the number of published resources.
 */
final class PortalMapClusterService
{
    public const RESOURCE_TYPE_DIMENSION = 'resource-type';

    private const TILE_SIZE = 256.0;

    private const MAX_MERCATOR_LATITUDE = 85.05112878;

    /**
     * @param  iterable<array{
     *     location_id: int,
     *     resource_type_slug: string|null,
     *     category_key?: string|null,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float}
     * }>  $locations
     * @param  array{north: float, south: float, east: float, west: float, width: int, height: int}  $viewport
     * @return array{
     *     features: list<array<string, mixed>>,
     *     meta: array{requestedZoom: int, effectiveZoom: int, visibleLocations: int, coarsened: bool}
     * }
     */
    public function cluster(
        iterable $locations,
        array $viewport,
        int $requestedZoom,
        string $dimension = self::RESOURCE_TYPE_DIMENSION,
    ): array {
        $maxFeatures = max(1, (int) config('portal_map.max_features', 1000));
        $clusterRadius = max(1, (int) config('portal_map.cluster_radius', 60));
        $shapeDetailZoom = (int) config('portal_map.shape_detail_zoom', 10);
        $effectiveZoom = $this->plausibleZoom($requestedZoom, $viewport);
        $coarsened = $effectiveZoom !== $requestedZoom;

        /** @var array<string, array<string, mixed>> $cells */
        $cells = [];
        $visibleLocations = 0;

        foreach ($locations as $location) {
            $visibleLocations++;
            [$cellX, $cellY] = $this->cellCoordinates(
                $location['latitude'],
                $location['longitude'],
                $effectiveZoom,
                $clusterRadius,
            );
            $key = $cellX.':'.$cellY;

            if (! isset($cells[$key])) {
                $cells[$key] = $this->newCell($cellX, $cellY, $location);

                continue;
            }

            $cells[$key] = $this->addLocation($cells[$key], $location);
        }

        while (count($cells) > $maxFeatures && $effectiveZoom > 0) {
            $cells = $this->mergeParentCells($cells);
            $effectiveZoom--;
            $coarsened = true;
        }

        // Zoom zero is not a natural lower bound for a configurable response
        // limit: small cluster radii can still produce more terminal cells
        // than the contract permits. Continue folding the terminal grid until
        // the hard bound is satisfied.
        $terminalAggregationDepth = 0;
        while (count($cells) > $maxFeatures) {
            $cells = $this->mergeParentCells($cells);
            $terminalAggregationDepth++;
            $coarsened = true;
        }

        ksort($cells);
        $features = [];

        foreach ($cells as $cell) {
            /** @var array<string, int> $resourceTypeCounts */
            $resourceTypeCounts = $cell['resource_type_counts'];
            ksort($resourceTypeCounts);
            /** @var array<string, int> $categoryCounts */
            $categoryCounts = $cell['category_counts'];
            ksort($categoryCounts);

            $position = [
                'lat' => $cell['latitude_sum'] / $cell['count'],
                'lng' => $this->circularLongitudeMean(
                    $cell['longitude_sine_sum'],
                    $cell['longitude_cosine_sum'],
                    $cell['fallback_longitude'],
                ),
            ];
            $bounds = [
                'north' => $cell['north'],
                'south' => $cell['south'],
                'east' => $cell['east'],
                'west' => $cell['west'],
            ];
            $navigationBounds = [
                'north' => $cell['navigation_north'],
                'south' => $cell['navigation_south'],
                'east' => $cell['navigation_east'],
                'west' => $cell['navigation_west'],
            ];
            $canReturnResource = $cell['count'] === 1
                && $cell['singleton_location_id'] !== null
                && ($cell['singleton_geometry_type'] === 'point' || $effectiveZoom >= $shapeDetailZoom);

            if ($canReturnResource) {
                $features[] = [
                    'kind' => 'resource-candidate',
                    'locationId' => $cell['singleton_location_id'],
                    'position' => $position,
                    'bounds' => $bounds,
                ];

                continue;
            }

            $features[] = [
                'kind' => 'cluster',
                'id' => 'z'.$effectiveZoom.($terminalAggregationDepth > 0 ? '-t'.$terminalAggregationDepth : '').':'.$cell['cell_x'].':'.$cell['cell_y'],
                'position' => $position,
                'bounds' => $bounds,
                'navigationBounds' => $navigationBounds,
                'count' => $cell['count'],
                'resourceTypeCounts' => $resourceTypeCounts,
                'composition' => [
                    'dimension' => $dimension,
                    'counts' => $categoryCounts,
                ],
            ];
        }

        return [
            'features' => $features,
            'meta' => [
                'requestedZoom' => $requestedZoom,
                'effectiveZoom' => $effectiveZoom,
                'visibleLocations' => $visibleLocations,
                'coarsened' => $coarsened,
            ],
        ];
    }

    /**
     * Resolve a server-issued cluster ID back to its bounded, ordered member
     * candidates for the exact viewport used to produce the cluster.
     *
     * @param  iterable<array{
     *     location_id: int,
     *     resource_type_slug: string|null,
     *     category_key?: string|null,
     *     geometry_type: string,
     *     latitude: float,
     *     longitude: float,
     *     bounds: array{north: float, south: float, east: float, west: float}
     * }>  $locations
     * @return array{features: list<array<string, mixed>>, total: int, page: int, perPage: int}|null
     */
    public function members(iterable $locations, string $clusterId, int $page, int $perPage): ?array
    {
        $descriptor = $this->parseClusterId($clusterId);
        if ($descriptor === null) {
            return null;
        }

        $clusterRadius = max(1, (int) config('portal_map.cluster_radius', 60));
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;
        $total = 0;
        $features = [];

        foreach ($locations as $location) {
            [$cellX, $cellY] = $this->cellCoordinates(
                $location['latitude'],
                $location['longitude'],
                $descriptor['zoom'],
                $clusterRadius,
            );

            for ($depth = 0; $depth < $descriptor['terminalDepth']; $depth++) {
                $cellX = (int) floor($cellX / 2);
                $cellY = (int) floor($cellY / 2);
            }

            if ($cellX !== $descriptor['cellX'] || $cellY !== $descriptor['cellY']) {
                continue;
            }

            $total++;
            if ($total <= $offset || count($features) >= $perPage) {
                continue;
            }

            $features[] = [
                'kind' => 'resource-candidate',
                'locationId' => $location['location_id'],
                'position' => [
                    'lat' => $location['latitude'],
                    'lng' => $location['longitude'],
                ],
                'bounds' => $location['bounds'],
            ];
        }

        return compact('features', 'total', 'page', 'perPage');
    }

    /**
     * @param  array{north: float, south: float, east: float, west: float, width: int, height: int}  $viewport
     */
    private function plausibleZoom(int $requestedZoom, array $viewport): int
    {
        $zoom = min($this->maxZoom(), max(0, $requestedZoom));
        $maxWidth = max(1, $viewport['width']) * 4;
        $maxHeight = max(1, $viewport['height']) * 4;

        while ($zoom > 0) {
            $worldSize = self::TILE_SIZE * (2 ** $zoom);
            $westX = (($viewport['west'] + 180.0) / 360.0) * $worldSize;
            $eastX = (($viewport['east'] + 180.0) / 360.0) * $worldSize;
            $pixelWidth = $viewport['west'] > $viewport['east']
                ? ($worldSize - $westX) + $eastX
                : abs($eastX - $westX);
            [, $northY] = $this->project($viewport['north'], 0.0, $zoom);
            [, $southY] = $this->project($viewport['south'], 0.0, $zoom);
            $pixelHeight = abs($southY - $northY);

            if ($pixelWidth <= $maxWidth && $pixelHeight <= $maxHeight) {
                break;
            }

            $zoom--;
        }

        return $zoom;
    }

    /**
     * @return array{float, float}
     */
    private function project(float $latitude, float $longitude, int $zoom): array
    {
        $latitude = min(self::MAX_MERCATOR_LATITUDE, max(-self::MAX_MERCATOR_LATITUDE, $latitude));
        $longitude = min(180.0, max(-180.0, $longitude));
        $worldSize = self::TILE_SIZE * (2 ** $zoom);
        $sinLatitude = sin(deg2rad($latitude));

        return [
            (($longitude + 180.0) / 360.0) * $worldSize,
            (0.5 - log((1.0 + $sinLatitude) / (1.0 - $sinLatitude)) / (4.0 * M_PI)) * $worldSize,
        ];
    }

    /** @return array{int, int} */
    private function cellCoordinates(float $latitude, float $longitude, int $zoom, int $clusterRadius): array
    {
        [$pixelX, $pixelY] = $this->project($latitude, $longitude, $zoom);

        return [
            (int) floor($pixelX / $clusterRadius),
            (int) floor($pixelY / $clusterRadius),
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function newCell(int $cellX, int $cellY, array $location): array
    {
        $slug = $location['resource_type_slug'] ?? 'other';
        $categoryKey = $location['category_key'] ?? $slug;
        $longitudeRadians = deg2rad($location['longitude']);

        return [
            'cell_x' => $cellX,
            'cell_y' => $cellY,
            'count' => 1,
            'latitude_sum' => $location['latitude'],
            'longitude_sine_sum' => sin($longitudeRadians),
            'longitude_cosine_sum' => cos($longitudeRadians),
            'fallback_longitude' => $location['longitude'],
            'north' => $location['bounds']['north'],
            'south' => $location['bounds']['south'],
            'east' => $location['bounds']['east'],
            'west' => $location['bounds']['west'],
            'navigation_north' => $location['latitude'],
            'navigation_south' => $location['latitude'],
            'navigation_east' => $location['longitude'],
            'navigation_west' => $location['longitude'],
            'resource_type_counts' => [$slug => 1],
            'category_counts' => [$categoryKey => 1],
            'singleton_location_id' => $location['location_id'],
            'singleton_geometry_type' => $location['geometry_type'],
        ];
    }

    /**
     * @param  array<string, mixed>  $cell
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function addLocation(array $cell, array $location): array
    {
        $slug = $location['resource_type_slug'] ?? 'other';
        $categoryKey = $location['category_key'] ?? $slug;
        $longitudeRadians = deg2rad($location['longitude']);
        $longitudeBounds = CircularLongitudeCoverage::merge(
            $cell['west'],
            $cell['east'],
            $location['bounds']['west'],
            $location['bounds']['east'],
        );
        $navigationLongitudeBounds = CircularLongitudeCoverage::merge(
            $cell['navigation_west'],
            $cell['navigation_east'],
            $location['longitude'],
            $location['longitude'],
        );
        $cell['count']++;
        $cell['latitude_sum'] += $location['latitude'];
        $cell['longitude_sine_sum'] += sin($longitudeRadians);
        $cell['longitude_cosine_sum'] += cos($longitudeRadians);
        $cell['north'] = max($cell['north'], $location['bounds']['north']);
        $cell['south'] = min($cell['south'], $location['bounds']['south']);
        $cell['east'] = $longitudeBounds['east'];
        $cell['west'] = $longitudeBounds['west'];
        $cell['navigation_north'] = max($cell['navigation_north'], $location['latitude']);
        $cell['navigation_south'] = min($cell['navigation_south'], $location['latitude']);
        $cell['navigation_east'] = $navigationLongitudeBounds['east'];
        $cell['navigation_west'] = $navigationLongitudeBounds['west'];
        $cell['resource_type_counts'][$slug] = ($cell['resource_type_counts'][$slug] ?? 0) + 1;
        $cell['category_counts'][$categoryKey] = ($cell['category_counts'][$categoryKey] ?? 0) + 1;
        $cell['singleton_location_id'] = null;
        $cell['singleton_geometry_type'] = null;

        return $cell;
    }

    /**
     * @param  array<string, array<string, mixed>>  $cells
     * @return array<string, array<string, mixed>>
     */
    private function mergeParentCells(array $cells): array
    {
        $parents = [];

        foreach ($cells as $cell) {
            $parentX = (int) floor($cell['cell_x'] / 2);
            $parentY = (int) floor($cell['cell_y'] / 2);
            $key = $parentX.':'.$parentY;

            if (! isset($parents[$key])) {
                $cell['cell_x'] = $parentX;
                $cell['cell_y'] = $parentY;
                $parents[$key] = $cell;

                continue;
            }

            $parent = $parents[$key];
            $longitudeBounds = CircularLongitudeCoverage::merge(
                $parent['west'],
                $parent['east'],
                $cell['west'],
                $cell['east'],
            );
            $navigationLongitudeBounds = CircularLongitudeCoverage::merge(
                $parent['navigation_west'],
                $parent['navigation_east'],
                $cell['navigation_west'],
                $cell['navigation_east'],
            );
            $parent['count'] += $cell['count'];
            $parent['latitude_sum'] += $cell['latitude_sum'];
            $parent['longitude_sine_sum'] += $cell['longitude_sine_sum'];
            $parent['longitude_cosine_sum'] += $cell['longitude_cosine_sum'];
            $parent['north'] = max($parent['north'], $cell['north']);
            $parent['south'] = min($parent['south'], $cell['south']);
            $parent['east'] = $longitudeBounds['east'];
            $parent['west'] = $longitudeBounds['west'];
            $parent['navigation_north'] = max($parent['navigation_north'], $cell['navigation_north']);
            $parent['navigation_south'] = min($parent['navigation_south'], $cell['navigation_south']);
            $parent['navigation_east'] = $navigationLongitudeBounds['east'];
            $parent['navigation_west'] = $navigationLongitudeBounds['west'];

            foreach ($cell['resource_type_counts'] as $slug => $count) {
                $parent['resource_type_counts'][$slug] = ($parent['resource_type_counts'][$slug] ?? 0) + $count;
            }

            foreach ($cell['category_counts'] as $categoryKey => $count) {
                $parent['category_counts'][$categoryKey] = ($parent['category_counts'][$categoryKey] ?? 0) + $count;
            }

            $parent['singleton_location_id'] = null;
            $parent['singleton_geometry_type'] = null;
            $parents[$key] = $parent;
        }

        return $parents;
    }

    /** @return array{zoom: int, terminalDepth: int, cellX: int, cellY: int}|null */
    private function parseClusterId(string $clusterId): ?array
    {
        if (preg_match('/^z(?<zoom>\d+)(?:-t(?<depth>\d+))?:(?<x>-?\d+):(?<y>-?\d+)$/', $clusterId, $matches) !== 1) {
            return null;
        }

        $zoom = (int) $matches['zoom'];
        $terminalDepth = $matches['depth'] !== '' ? (int) $matches['depth'] : 0;
        if ($zoom > $this->maxZoom() || $terminalDepth > 30) {
            return null;
        }

        return [
            'zoom' => $zoom,
            'terminalDepth' => $terminalDepth,
            'cellX' => (int) $matches['x'],
            'cellY' => (int) $matches['y'],
        ];
    }

    private function maxZoom(): int
    {
        return max(0, (int) config('portal_map.max_zoom', 18));
    }

    private function circularLongitudeMean(float $sine, float $cosine, float $fallback): float
    {
        if (abs($sine) < 1.0E-12 && abs($cosine) < 1.0E-12) {
            return $fallback;
        }

        $longitude = rad2deg(atan2($sine, $cosine));

        return abs($longitude + 180.0) < 1.0E-12 ? 180.0 : $longitude;
    }
}
