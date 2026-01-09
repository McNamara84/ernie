<?php

namespace Database\Factories;

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
        $hasDoi = fake()->boolean(70); // 70% of landing pages have a DOI

        // Generate DOI suffix in a more realistic format (e.g., "gfz.2024.001")
        // Real DOI suffixes typically use dots and forward slashes, not word-hyphens.
        // Not using unique() since database constraints ensure uniqueness on
        // (doi_prefix, slug) combination, not DOI suffix alone. This also prevents
        // OverflowException in large test suites that create many landing pages.
        $doiSuffix = sprintf(
            'gfz.%d.%05d',
            fake()->numberBetween(2020, 2030),
            fake()->numberBetween(1, 99999)
        );

        return [
            'resource_id' => Resource::factory(),
            'doi_prefix' => $hasDoi ? "10.5880/{$doiSuffix}" : null,
            // Generate a deterministic slug using UUID to avoid database constraint
            // violations in test suites. The unique constraints are on (doi_prefix, slug)
            // and (resource_id, slug), so using random slugs could cause collisions.
            // Using Str::uuid() instead of uniqid() because:
            // 1. UUIDs are guaranteed unique even in parallel test execution
            // 2. uniqid() is time-based and can collide in tight loops
            // 3. Produces predictable, readable slugs for debugging
            'slug' => fn () => 'test-dataset-'.Str::uuid()->toString(),
            'template' => 'default_gfz', // Only template that exists currently
            'ftp_url' => fake()->optional(0.3)->url(),
            'contact_url' => fake()->optional(0.5)->url(),
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
     * Indicate that the landing page has no DOI (draft mode).
     */
    public function withoutDoi(): static
    {
        return $this->state(fn (array $attributes) => [
            'doi_prefix' => null,
        ]);
    }

    /**
     * Indicate that the landing page has a specific DOI.
     */
    public function withDoi(string $doi): static
    {
        return $this->state(fn (array $attributes) => [
            'doi_prefix' => $doi,
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
