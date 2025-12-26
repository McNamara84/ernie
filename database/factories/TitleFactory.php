<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Resource;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Title>
 */
class TitleFactory extends Factory
{
    protected $model = Title::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create MainTitle type (default, no specific type)
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'MainTitle'],
            ['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true]
        );

        return [
            'resource_id' => Resource::factory(),
            'value' => $this->faker->sentence(4),
            'title_type_id' => $titleType->id,
            'language' => 'en',
        ];
    }

    /**
     * Indicate that the title is an alternative title.
     */
    public function alternativeTitle(): static
    {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'AlternativeTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'title_type_id' => $titleType->id,
        ]);
    }

    /**
     * Indicate that the title is a subtitle.
     */
    public function subtitle(): static
    {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'Subtitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'title_type_id' => $titleType->id,
        ]);
    }

    /**
     * Indicate that the title is a translated title.
     */
    public function translatedTitle(): static
    {
        $titleType = TitleType::firstOrCreate(
            ['slug' => 'TranslatedTitle'],
            ['name' => 'Translated Title', 'slug' => 'TranslatedTitle', 'is_active' => true]
        );

        return $this->state(fn (array $attributes) => [
            'title_type_id' => $titleType->id,
        ]);
    }
}
