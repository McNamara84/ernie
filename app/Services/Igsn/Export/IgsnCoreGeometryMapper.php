<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use App\Models\Resource;

final class IgsnCoreGeometryMapper
{
    /** @return array{places: list<string>, geometries: array<string, list<array<string, string>>>, omissions: list<string>} */
    public function map(Resource $resource): array
    {
        $places = $candidates = $omissions = [];
        $system = strtoupper(str_replace(' ', '', trim((string) $resource->igsnMetadata?->coordinate_system)));
        // Resource geoLocations use DataCite's WGS84 convention when no IGSN override exists.
        $wgs84 = in_array($system, ['', 'WGS84', 'EPSG:4326', '4326'], true);
        foreach ($resource->geoLocations->sortBy('position') as $location) {
            foreach ([$location->place, $location->country, $location->province, $location->county, $location->city] as $place) {
                if (is_string($place) && trim($place) !== '') {
                    $places[trim($place)] = true;
                }
            }
            if (! $wgs84) {
                $omissions[] = 'coordinate_system_unsupported';

                continue;
            }
            if ($location->point_longitude !== null || $location->point_latitude !== null) {
                $point = $this->point($location->point_longitude, $location->point_latitude);
                if ($point === null) {
                    $omissions[] = 'point_invalid';
                } else {
                    $candidates['point'][json_encode($point, JSON_THROW_ON_ERROR)] = [$point];
                }
            }
            $bounds = [$location->west_bound_longitude, $location->east_bound_longitude, $location->south_bound_latitude, $location->north_bound_latitude];
            if (array_any($bounds, static fn ($value): bool => $value !== null)) {
                $southwest = $this->point($bounds[0], $bounds[2]);
                $northeast = $this->point($bounds[1], $bounds[3]);
                if ($southwest === null || $northeast === null || (float) $bounds[2] > (float) $bounds[3]) {
                    $omissions[] = 'box_invalid';
                } else {
                    $box = ['west' => $southwest['longitude'], 'east' => $northeast['longitude'], 'south' => $southwest['latitude'], 'north' => $northeast['latitude']];
                    $candidates['box'][json_encode($box, JSON_THROW_ON_ERROR)] = [$box];
                }
            }
            if ($location->geo_type !== 'line' && $location->polygon_points !== null && $location->polygon_points !== []) {
                $points = [];
                foreach ($location->polygon_points as $raw) {
                    $point = $this->storedPoint($raw);
                    if ($point === null) {
                        $points = [];
                        break;
                    }
                    $points[] = $point;
                }
                // The editor stores valid rings with implicit closure; Core requires the closing vertex.
                if (count($points) >= 3 && $points[0] !== $points[count($points) - 1]) {
                    $points[] = $points[0];
                }
                if (count($points) < 4 || $points[0] !== $points[count($points) - 1]) {
                    $omissions[] = 'polygon_invalid';
                } else {
                    $candidates['polygon'][json_encode($points, JSON_THROW_ON_ERROR)] = $points;
                }
            }
        }
        $geometries = [];
        foreach ($candidates as $type => $values) {
            if (count($values) === 1) {
                $geometries[$type] = array_values($values)[0];
            } else {
                $omissions[] = $type.'_ambiguous';
            }
        }

        return ['places' => array_map('strval', array_keys($places)), 'geometries' => $geometries, 'omissions' => array_values(array_unique($omissions))];
    }

    /** @return array{longitude: string, latitude: string}|null */
    private function storedPoint(mixed $point): ?array
    {
        return is_array($point) ? $this->point($point['longitude'] ?? null, $point['latitude'] ?? null) : null;
    }

    /** @return array{longitude: string, latitude: string}|null */
    private function point(mixed $longitude, mixed $latitude): ?array
    {
        if (! is_numeric($longitude) || ! is_numeric($latitude) || ! is_finite((float) $longitude) || ! is_finite((float) $latitude)
            || abs((float) $longitude) > 180 || abs((float) $latitude) > 90) {
            return null;
        }

        return ['longitude' => (string) (float) $longitude, 'latitude' => (string) (float) $latitude];
    }
}
