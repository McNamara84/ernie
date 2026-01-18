<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resource>
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
        // Get or create default resource type
        $resourceType = ResourceType::firstOrCreate(
            ['slug' => 'Dataset'],
            ['name' => 'Dataset', 'slug' => 'Dataset', 'is_active' => true]
        );

        // Get or create default language
        $language = Language::firstOrCreate(
            ['code' => 'en'],
            ['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]
        );

        // Get or create default publisher with full DataCite 4.6 fields
        $publisher = Publisher::firstOrCreate(
            ['name' => 'GFZ Data Services'],
            [
                'name' => 'GFZ Data Services',
                'identifier' => 'https://doi.org/10.17616/R3VQ0S',
                'identifier_scheme' => 're3data',
                'scheme_uri' => 'https://re3data.org/',
                'language' => 'en',
                'is_default' => true,
            ]
        );

        return [
            'doi' => '10.'.fake()->numberBetween(1000, 9999).'/'.fake()->slug(2),
            'identifier_type' => 'DOI',
            'publication_year' => (int) fake()->year(),
            'resource_type_id' => $resourceType->id,
            'version' => '1.0',
            'language_id' => $language->id,
            'publisher_id' => $publisher->id,
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
     * Indicate that the resource should have a specific publication year
     */
    public function withPublicationYear(int $year): static
    {
        return $this->state(fn (array $attributes) => [
            'publication_year' => $year,
        ]);
    }

    /**
     * @deprecated Use withPublicationYear() instead
     */
    public function withYear(int $year): static
    {
        return $this->withPublicationYear($year);
    }
}
