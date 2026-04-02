<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Datacenter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Datacenter>
 */
class DatacenterFactory extends Factory
{
    protected $model = Datacenter::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
        ];
    }

    /**
     * Create a datacenter with a specific name.
     */
    public function withName(string $name): static
    {
        return $this->state(['name' => $name]);
    }
}
