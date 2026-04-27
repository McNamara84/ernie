<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItem;
use App\Models\RelatedItemCreator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItemCreator>
 */
class RelatedItemCreatorFactory extends Factory
{
    protected $model = RelatedItemCreator::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $given = fake()->firstName();
        $family = fake()->lastName();

        return [
            'related_item_id' => RelatedItem::factory(),
            'name_type' => 'Personal',
            'name' => "{$family}, {$given}",
            'given_name' => $given,
            'family_name' => $family,
            'name_identifier' => null,
            'name_identifier_scheme' => null,
            'position' => 0,
        ];
    }

    public function organizational(?string $name = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name_type' => 'Organizational',
            'name' => $name ?? fake()->company(),
            'given_name' => null,
            'family_name' => null,
        ]);
    }

    public function withOrcid(string $orcid = '0000-0002-1825-0097'): static
    {
        return $this->state(fn (array $attributes) => [
            'name_identifier' => $orcid,
            'name_identifier_scheme' => 'ORCID',
            'scheme_uri' => 'https://orcid.org',
        ]);
    }
}
