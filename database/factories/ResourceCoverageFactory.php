<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Resource;
use App\Models\ResourceCoverage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceCoverage>
 */
class ResourceCoverageFactory extends Factory
{
    protected $model = ResourceCoverage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'type' => 'point',
            'lat_min' => fake()->latitude(),
            'lat_max' => null,
            'lon_min' => fake()->longitude(),
            'lon_max' => null,
            'polygon_points' => null,
            'start_date' => null,
            'end_date' => null,
            'start_time' => null,
            'end_time' => null,
            'timezone' => 'UTC',
            'description' => null,
        ];
    }

    /**
     * Create a bounding box coverage
     */
    public function box(): static
    {
        return $this->state(function (array $attributes) {
            $lat1 = fake()->latitude();
            $lat2 = fake()->latitude();
            $lon1 = fake()->longitude();
            $lon2 = fake()->longitude();

            return [
                'type' => 'box',
                'lat_min' => min($lat1, $lat2),
                'lat_max' => max($lat1, $lat2),
                'lon_min' => min($lon1, $lon2),
                'lon_max' => max($lon1, $lon2),
                'polygon_points' => null,
            ];
        });
    }

    /**
     * Create a polygon coverage with realistic coordinates
     */
    public function polygon(): static
    {
        return $this->state(function (array $attributes) {
            // Generate a simple rectangular polygon around a center point
            $centerLat = fake()->latitude();
            $centerLon = fake()->longitude();
            $offset = 0.5; // ~55km at equator

            return [
                'type' => 'polygon',
                'lat_min' => null,
                'lat_max' => null,
                'lon_min' => null,
                'lon_max' => null,
                'polygon_points' => [
                    ['lat' => round($centerLat + $offset, 6), 'lon' => round($centerLon - $offset, 6)],
                    ['lat' => round($centerLat + $offset, 6), 'lon' => round($centerLon + $offset, 6)],
                    ['lat' => round($centerLat - $offset, 6), 'lon' => round($centerLon + $offset, 6)],
                    ['lat' => round($centerLat - $offset, 6), 'lon' => round($centerLon - $offset, 6)],
                ],
            ];
        });
    }

    /**
     * Create a coverage with temporal data
     */
    public function withTemporal(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_date' => fake()->date(),
            'end_date' => fake()->date(),
            'start_time' => fake()->time('H:i:s'),
            'end_time' => fake()->time('H:i:s'),
            'timezone' => fake()->randomElement(['UTC', 'Europe/Berlin', 'America/New_York']),
        ]);
    }

    /**
     * Create a coverage with description
     */
    public function withDescription(): static
    {
        return $this->state(fn (array $attributes) => [
            'description' => fake()->sentence(),
        ]);
    }
}
