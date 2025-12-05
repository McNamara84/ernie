<?php

namespace Database\Factories;

use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\LandingPage>
 */
class LandingPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $isPublished = fake()->boolean(50);

        return [
            'resource_id' => Resource::factory(),
            'slug' => fake()->unique()->slug(),
            'template' => fake()->randomElement(['default_gfz', 'minimal', 'detailed']),
            'ftp_url' => fake()->optional(0.3)->url(),
            'is_published' => $isPublished,
            'preview_token' => Str::random(64),
            'published_at' => $isPublished ? fake()->dateTimeBetween('-1 year', 'now') : null,
            'view_count' => fake()->numberBetween(0, 1000),
            'last_viewed_at' => fake()->optional(0.7)->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Indicate that the landing page is in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the landing page is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_published' => true,
            'published_at' => now(),
        ]);
    }
}
