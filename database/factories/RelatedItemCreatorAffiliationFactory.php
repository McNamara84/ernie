<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItemCreatorAffiliation>
 */
class RelatedItemCreatorAffiliationFactory extends Factory
{
    protected $model = RelatedItemCreatorAffiliation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'related_item_creator_id' => RelatedItemCreator::factory(),
            'name' => fake()->company(),
            'affiliation_identifier' => null,
            'scheme' => null,
            'scheme_uri' => null,
            'position' => 0,
        ];
    }

    public function withRor(string $ror = 'https://ror.org/04wxnsj81'): static
    {
        return $this->state(fn (array $attributes) => [
            'affiliation_identifier' => $ror,
            'scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org',
        ]);
    }
}
