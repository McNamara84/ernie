<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Description>
 */
class DescriptionFactory extends Factory
{
    protected $model = Description::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create Abstract type (default)
        $descriptionType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );

        return [
            'resource_id' => Resource::factory(),
            'value' => fake()->paragraphs(2, true),
            'description_type_id' => $descriptionType->id,
            'language' => 'en',
        ];
    }

    /**
     * Create an abstract description.
     */
    public function abstract(): static
    {
        $descriptionType = DescriptionType::firstOrCreate(
            ['slug' => 'Abstract'],
            ['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'description_type_id' => $descriptionType->id,
        ]);
    }

    /**
     * Create a methods description.
     */
    public function methods(): static
    {
        $descriptionType = DescriptionType::firstOrCreate(
            ['slug' => 'Methods'],
            ['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'description_type_id' => $descriptionType->id,
        ]);
    }

    /**
     * Create a technical info description.
     */
    public function technicalInfo(): static
    {
        $descriptionType = DescriptionType::firstOrCreate(
            ['slug' => 'TechnicalInfo'],
            ['name' => 'Technical Info', 'slug' => 'TechnicalInfo', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'description_type_id' => $descriptionType->id,
        ]);
    }
}
