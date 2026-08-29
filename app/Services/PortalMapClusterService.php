<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Bounded, database-independent clustering for public portal map locations.
 *
 * Input rows are scalar projections produced by PortalMapService. No Eloquent
 * models are retained, so memory usage follows visible grid cells rather than
 * the number of published resources.
 */
final class PortalMapClusterService
{
    private const TILE_SIZE = 256.0;

    private const MAX_MERCATOR_LATITUDE = 85.05112878;

    /**
     * @param  iterable<array{
     *     location_id: int,
     *     resource_type_slug: string|null,
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
    public function cluster(iterable $locations, array $viewport, int $requestedZoom): array
    {
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
            [$pixelX, $pixelY] = $this->project(
                $location['latitude'],
                $location['longitude'],
                $effectiveZoom,
            );
            $cellX = (int) floor($pixelX / $clusterRadius);
            $cellY = (int) floor($pixelY / $clusterRadius);
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
                'count' => $cell['count'],
                'resourceTypeCounts' => $resourceTypeCounts,
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
     * @param  array{north: float, south: float, east: float, west: float, width: int, height: int}  $viewport
     */
    private function plausibleZoom(int $requestedZoom, array $viewport): int
    {
        $zoom = min(18, max(0, $requestedZoom));
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

    /**
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    private function newCell(int $cellX, int $cellY, array $location): array
    {
        $slug = $location['resource_type_slug'] ?? 'other';
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
            'resource_type_counts' => [$slug => 1],
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
        $longitudeRadians = deg2rad($location['longitude']);
        $cell['count']++;
        $cell['latitude_sum'] += $location['latitude'];
        $cell['longitude_sine_sum'] += sin($longitudeRadians);
        $cell['longitude_cosine_sum'] += cos($longitudeRadians);
        $cell['north'] = max($cell['north'], $location['bounds']['north']);
        $cell['south'] = min($cell['south'], $location['bounds']['south']);
        $cell['east'] = max($cell['east'], $location['bounds']['east']);
        $cell['west'] = min($cell['west'], $location['bounds']['west']);
        $cell['resource_type_counts'][$slug] = ($cell['resource_type_counts'][$slug] ?? 0) + 1;
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
            $parent['count'] += $cell['count'];
            $parent['latitude_sum'] += $cell['latitude_sum'];
            $parent['longitude_sine_sum'] += $cell['longitude_sine_sum'];
            $parent['longitude_cosine_sum'] += $cell['longitude_cosine_sum'];
            $parent['north'] = max($parent['north'], $cell['north']);
            $parent['south'] = min($parent['south'], $cell['south']);
            $parent['east'] = max($parent['east'], $cell['east']);
            $parent['west'] = min($parent['west'], $cell['west']);

            foreach ($cell['resource_type_counts'] as $slug => $count) {
                $parent['resource_type_counts'][$slug] = ($parent['resource_type_counts'][$slug] ?? 0) + $count;
            }

            $parent['singleton_location_id'] = null;
            $parent['singleton_geometry_type'] = null;
            $parents[$key] = $parent;
        }

        return $parents;
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
