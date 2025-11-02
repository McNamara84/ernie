<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<resource>
 */
class ResourceFactory extends Factory
{
    protected $model = Resource::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create default resource type and language
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'dataset'],
            ['name' => 'Dataset', 'slug' => 'dataset']
        );

        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English']
        );

        return [
            'doi' => '10.'.fake()->numberBetween(1000, 9999).'/'.fake()->slug(2),
            'year' => fake()->year(),
            'resource_type_id' => $resourceType->id,
            'version' => '1.0',
            'language_id' => $language->id,
        ];
    }

    /**
     * Indicate that the resource should have a specific DOI
     */
    public function withDoi(string $doi): static
    {
        return $this->state(fn (array $attributes) => [
            'doi' => $doi,
        ]);
    }

    /**
     * Indicate that the resource should have a specific year
     */
    public function withYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'year' => $year,
        ]);
    }
}
