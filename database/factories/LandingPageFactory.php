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
        return [
            'resource_id' => Resource::factory(),
            'template' => LandingPage::TEMPLATE_DEFAULT_GFZ,
            'ftp_url' => fake()->optional(0.7)->url(),
            'status' => fake()->randomElement([LandingPage::STATUS_DRAFT, LandingPage::STATUS_PUBLISHED]),
            'preview_token' => Str::random(64),
            'published_at' => fake()->optional(0.5)->dateTimeBetween('-1 year', 'now'),
            'view_count' => fake()->numberBetween(0, 1000),
            'last_viewed_at' => fake()->optional(0.6)->dateTimeBetween('-1 month', 'now'),
        ];
    }

    /**
     * Indicate that the landing page is in draft status.
     */
    public function draft(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LandingPage::STATUS_DRAFT,
            'published_at' => null,
        ]);
    }

    /**
     * Indicate that the landing page is published.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => LandingPage::STATUS_PUBLISHED,
            'published_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    /**
     * Indicate that the landing page has an FTP URL.
     */
    public function withFtpUrl(): static
    {
        return $this->state(fn (array $attributes) => [
            'ftp_url' => 'https://datapub.gfz-potsdam.de/download/'.fake()->sha256(),
        ]);
    }

    /**
     * Indicate that the landing page has no views yet.
     */
    public function noViews(): static
    {
        return $this->state(fn (array $attributes) => [
            'view_count' => 0,
            'last_viewed_at' => null,
        ]);
    }
}

