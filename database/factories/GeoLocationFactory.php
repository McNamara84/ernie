<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GeoLocation;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GeoLocation>
 */
class GeoLocationFactory extends Factory
{
    protected $model = GeoLocation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'place' => fake()->city().', '.fake()->country(),
            'point_longitude' => null,
            'point_latitude' => null,
            'west_bound_longitude' => null,
            'east_bound_longitude' => null,
            'south_bound_latitude' => null,
            'north_bound_latitude' => null,
            'polygon_points' => null,
            'in_polygon_point_longitude' => null,
            'in_polygon_point_latitude' => null,
        ];
    }

    /**
     * Create a geo location with a point.
     */
    public function withPoint(?float $longitude = null, ?float $latitude = null): static
    {
        return $this->state(fn (array $attributes) => [
            'point_longitude' => $longitude ?? fake()->longitude(),
            'point_latitude' => $latitude ?? fake()->latitude(),
        ]);
    }

    /**
     * Create a geo location with a bounding box.
     */
    public function withBox(
        ?float $west = null,
        ?float $east = null,
        ?float $south = null,
        ?float $north = null
    ): static {
        return $this->state(fn (array $attributes) => [
            'west_bound_longitude' => $west ?? fake()->longitude(-180, 0),
            'east_bound_longitude' => $east ?? fake()->longitude(0, 180),
            'south_bound_latitude' => $south ?? fake()->latitude(-90, 0),
            'north_bound_latitude' => $north ?? fake()->latitude(0, 90),
        ]);
    }

    /**
     * Create a geo location with a polygon.
     *
     * @param  array<int, array{longitude: float, latitude: float}>|null  $points
     */
    public function withPolygon(?array $points = null): static
    {
        return $this->state(function (array $attributes) use ($points) {
            if ($points === null) {
                // Create a simple triangle
                $centerLon = fake()->longitude();
                $centerLat = fake()->latitude();
                $points = [
                    ['longitude' => $centerLon, 'latitude' => $centerLat + 0.1],
                    ['longitude' => $centerLon + 0.1, 'latitude' => $centerLat - 0.1],
                    ['longitude' => $centerLon - 0.1, 'latitude' => $centerLat - 0.1],
                    ['longitude' => $centerLon, 'latitude' => $centerLat + 0.1], // Close the polygon
                ];
            }

            return [
                'polygon_points' => $points,
                'in_polygon_point_longitude' => $centerLon ?? $points[0]['longitude'],
                'in_polygon_point_latitude' => $centerLat ?? $points[0]['latitude'],
            ];
        });
    }
}
