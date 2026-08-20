<?php

declare(strict_types=1);

namespace App\Services\Igsn;

final class IgsnGeometryNormalizer
{
    /**
     * @param  array<int, array{latitude: mixed, longitude: mixed}>  $pairs
     * @return array<string, mixed>|null
     */
    public function normalize(array $pairs): ?array
    {
        $normalized = [];
        $seen = [];

        foreach ($pairs as $pair) {
            $latitude = $this->coordinate($pair['latitude'] ?? null, -90.0, 90.0);
            $longitude = $this->coordinate($pair['longitude'] ?? null, -180.0, 180.0);

            if ($latitude === null || $longitude === null) {
                continue;
            }

            $key = sprintf('%.12F|%.12F', $latitude, $longitude);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $normalized[] = ['latitude' => $latitude, 'longitude' => $longitude];
        }

        return match (count($normalized)) {
            0 => null,
            1 => [
                'geo_type' => 'point',
                'point_latitude' => $normalized[0]['latitude'],
                'point_longitude' => $normalized[0]['longitude'],
            ],
            2 => [
                'geo_type' => 'box',
                'south_bound_latitude' => min($normalized[0]['latitude'], $normalized[1]['latitude']),
                'north_bound_latitude' => max($normalized[0]['latitude'], $normalized[1]['latitude']),
                'west_bound_longitude' => min($normalized[0]['longitude'], $normalized[1]['longitude']),
                'east_bound_longitude' => max($normalized[0]['longitude'], $normalized[1]['longitude']),
            ],
            default => [
                'geo_type' => 'polygon',
                'polygon_points' => $normalized,
            ],
        };
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return null;
        }

        $coordinate = (float) $value;

        return is_finite($coordinate) && $coordinate >= $minimum && $coordinate <= $maximum
            ? $coordinate
            : null;
    }
}
