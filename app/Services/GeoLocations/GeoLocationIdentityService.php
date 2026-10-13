<?php

declare(strict_types=1);

namespace App\Services\GeoLocations;

use App\Models\GeoLocation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;

final class GeoLocationIdentityService
{
    public const FIELDS = [
        'geo_type', 'place', 'point_longitude', 'point_latitude',
        'west_bound_longitude', 'east_bound_longitude', 'south_bound_latitude', 'north_bound_latitude',
        'polygon_points', 'in_polygon_point_longitude', 'in_polygon_point_latitude',
        'elevation', 'elevation_unit', 'location_type', 'location_description', 'locality_description',
        'country', 'province', 'county', 'city',
        'start_date', 'end_date', 'temporal_mode', 'start_time', 'end_time', 'timezone',
    ];

    public function equal(GeoLocation $left, GeoLocation $right): bool
    {
        return $this->values($left) === $this->values($right);
    }

    public function samePoints(mixed $left, mixed $right): bool
    {
        return $this->canonicalPoints($left) === $this->canonicalPoints($right);
    }

    /** @return array<string, mixed> */
    private function values(GeoLocation $location): array
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $location->getAttribute($field);
            $values[$field] = $field === 'polygon_points' ? $this->canonicalPoints($value) : $value;
        }

        return $values;
    }

    private function canonicalPoints(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $child) {
            if (in_array($key, ['latitude', 'longitude', 'lat', 'lon'], true) && is_numeric($child)) {
                try {
                    $value[$key] = (string) BigDecimal::of(trim((string) $child))->strippedOfTrailingZeros();
                } catch (NumberFormatException) {
                    $value[$key] = $child;
                }
            } else {
                $value[$key] = $this->canonicalPoints($child);
            }
        }

        return $value;
    }
}
