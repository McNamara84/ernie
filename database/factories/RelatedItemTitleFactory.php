<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItem;
use App\Models\RelatedItemTitle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItemTitle>
 */
class RelatedItemTitleFactory extends Factory
{
    protected $model = RelatedItemTitle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'related_item_id' => RelatedItem::factory(),
            'title' => fake()->sentence(6),
            'title_type' => 'MainTitle',
            'language' => 'en',
            'position' => 0,
        ];
    }

    public function subtitle(): static
    {
        return $this->state(fn (array $attributes) => ['title_type' => 'Subtitle']);
    }
}
