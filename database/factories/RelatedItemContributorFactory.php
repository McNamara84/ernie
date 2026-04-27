<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItemContributor>
 */
class RelatedItemContributorFactory extends Factory
{
    protected $model = RelatedItemContributor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $given = fake()->firstName();
        $family = fake()->lastName();

        return [
            'related_item_id' => RelatedItem::factory(),
            'contributor_type' => 'Editor',
            'name_type' => 'Personal',
            'name' => "{$family}, {$given}",
            'given_name' => $given,
            'family_name' => $family,
            'position' => 0,
        ];
    }
}
