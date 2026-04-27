<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItemContributor;
use App\Models\RelatedItemContributorAffiliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItemContributorAffiliation>
 */
class RelatedItemContributorAffiliationFactory extends Factory
{
    protected $model = RelatedItemContributorAffiliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'related_item_contributor_id' => RelatedItemContributor::factory(),
            'name' => fake()->company(),
            'position' => 0,
        ];
    }
}
