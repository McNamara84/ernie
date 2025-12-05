<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Publisher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Publisher>
 */
class PublisherFactory extends Factory
{
    protected $model = Publisher::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Data Services',
            'identifier' => null,
            'identifier_scheme' => null,
            'scheme_uri' => null,
            'language' => 'en',
            'is_default' => false,
        ];
    }

    /**
     * Create the GFZ Data Services publisher.
     */
    public function gfz(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'GFZ Data Services',
            'identifier' => 'https://doi.org/10.17616/R3B596',
            'identifier_scheme' => 're3data',
            'scheme_uri' => 'https://re3data.org/',
            'is_default' => true,
        ]);
    }

    /**
     * Mark as default publisher.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
