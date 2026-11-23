<?php

declare(strict_types=1);

namespace App\Services\Iso19115;

use App\Models\GeoLocation;

/** Converts stored geography without inventing coordinates for incomplete legacy data. */
final class Iso19115JsonGeometryMapper
{
    /** @return array<string, mixed>|null */
    public function geometry(GeoLocation $location): ?array
    {
        $sourcePoints = $location->getAttribute('polygon_points');
        $line = $location->geo_type === 'line';
        if ($line || $location->geo_type === 'polygon' || (is_array($sourcePoints) && count($sourcePoints) >= 3)) {
            if (! is_array($sourcePoints)) {
                return null;
            }
            $points = [];
            foreach ($sourcePoints as $point) {
                if (! is_array($point) || ! $this->coordinate($point['longitude'] ?? null, 180)
                    || ! $this->coordinate($point['latitude'] ?? null, 90)) {
                    return null;
                }
                $points[] = [(float) $point['longitude'], (float) $point['latitude']];
            }
            $unique = array_unique(array_map(static fn (array $point): string => implode(',', $point), $points));
            if (count($unique) < ($line ? 2 : 3) || $points === []) {
                return null;
            }
            if ($line) {
                return ['type' => 'LineString', 'coordinates' => $points];
            }
            if ($points[0] !== $points[array_key_last($points)]) {
                $points[] = $points[0];
            }

            return ['type' => 'Polygon', 'coordinates' => [$points]];
        }

        if ($location->hasBox()) {
            $west = $location->west_bound_longitude;
            $east = $location->east_bound_longitude;
            $south = $location->south_bound_latitude;
            $north = $location->north_bound_latitude;
            if (! $this->coordinate($west, 180) || ! $this->coordinate($east, 180)
                || ! $this->coordinate($south, 90) || ! $this->coordinate($north, 90) || $south > $north) {
                return null;
            }

            return $this->box((float) $west, (float) $south, (float) $east, (float) $north);
        }

        if ($location->hasPoint() && $this->coordinate($location->point_longitude, 180)
            && $this->coordinate($location->point_latitude, 90)) {
            return ['type' => 'Point', 'coordinates' => [(float) $location->point_longitude, (float) $location->point_latitude]];
        }

        return null;
    }

    /** @return array{type: string, coordinates: array<mixed>} */
    public function box(float $west, float $south, float $east, float $north): array
    {
        if ($west > $east) {
            return ['type' => 'MultiPolygon', 'coordinates' => [
                [$this->ring($west, $south, 180.0, $north)],
                [$this->ring(-180.0, $south, $east, $north)],
            ]];
        }

        return ['type' => 'Polygon', 'coordinates' => [$this->ring($west, $south, $east, $north)]];
    }

    private function coordinate(mixed $value, int $limit): bool
    {
        return is_numeric($value) && is_finite((float) $value) && abs((float) $value) <= $limit;
    }

    /** @return list<array{float, float}> */
    private function ring(float $west, float $south, float $east, float $north): array
    {
        return [[$west, $south], [$east, $south], [$east, $north], [$west, $north], [$west, $south]];
    }
}
