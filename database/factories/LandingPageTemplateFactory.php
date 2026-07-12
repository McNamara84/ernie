<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LandingPageTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LandingPageTemplate>
 */
class LandingPageTemplateFactory extends Factory
{
    protected $model = LandingPageTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var string $name */
        $name = fake()->unique()->words(3, true);
        $name .= ' Template';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'is_default' => false,
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'logo_path' => null,
            'logo_filename' => null,
            'right_column_order' => LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
            'left_column_order' => LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS,
            'creator_display_limit' => LandingPageTemplate::DEFAULT_DISPLAY_LIMIT,
            'contributor_display_limit' => LandingPageTemplate::DEFAULT_DISPLAY_LIMIT,
            'citation_author_display_limit' => LandingPageTemplate::DEFAULT_DISPLAY_LIMIT,
            'created_by' => User::factory(),
        ];
    }

    /**
     * Indicate that this is the default template.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => LandingPageTemplate::DEFAULT_TEMPLATE_NAME,
            'slug' => LandingPageTemplate::DEFAULT_TEMPLATE_SLUG,
            'is_default' => true,
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_RESOURCE,
            'left_column_order' => LandingPageTemplate::RESOURCE_LEFT_COLUMN_SECTIONS,
            'created_by' => null,
        ]);
    }

    /**
     * Indicate that this is the default IGSN template.
     */
    public function defaultIgsn(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_NAME,
            'slug' => LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG,
            'is_default' => true,
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
            'left_column_order' => LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS,
            'created_by' => null,
        ]);
    }

    /**
     * Indicate that this is an IGSN-typed template.
     */
    public function igsn(): static
    {
        return $this->state(fn (array $attributes) => [
            'template_type' => LandingPageTemplate::TEMPLATE_TYPE_IGSN,
            'left_column_order' => LandingPageTemplate::IGSN_LEFT_COLUMN_SECTIONS,
        ]);
    }

    /**
     * Set a custom section order.
     *
     * @param  array<int, string>  $rightColumn
     * @param  array<int, string>  $leftColumn
     */
    public function withSectionOrder(array $rightColumn, array $leftColumn): static
    {
        return $this->state(fn (array $attributes) => [
            'right_column_order' => $rightColumn,
            'left_column_order' => $leftColumn,
        ]);
    }

    /**
     * Set a custom logo.
     */
    public function withLogo(string $path = 'landing-page-logos/test/logo.png', string $filename = 'logo.png'): static
    {
        return $this->state(fn (array $attributes) => [
            'logo_path' => $path,
            'logo_filename' => $filename,
        ]);
    }
}
