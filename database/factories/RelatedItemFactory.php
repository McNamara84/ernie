<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RelatedItem;
use App\Models\RelationType;
use App\Models\Resource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RelatedItem>
 */
class RelatedItemFactory extends Factory
{
    protected $model = RelatedItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $relationType = RelationType::firstOrCreate(
            ['slug' => 'Cites'],
            ['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true]
        );

        return [
            'resource_id' => Resource::factory(),
            'related_item_type' => 'JournalArticle',
            'relation_type_id' => $relationType->id,
            'publication_year' => fake()->year(),
            'volume' => (string) fake()->numberBetween(1, 50),
            'issue' => (string) fake()->numberBetween(1, 12),
            'first_page' => (string) fake()->numberBetween(1, 100),
            'last_page' => (string) fake()->numberBetween(101, 200),
            'publisher' => fake()->company(),
            'position' => 0,
        ];
    }

    public function withIdentifier(string $identifier = '10.1234/example', string $type = 'DOI'): static
    {
        return $this->state(fn (array $attributes) => [
            'identifier' => $identifier,
            'identifier_type' => $type,
        ]);
    }

    public function book(): static
    {
        return $this->state(fn (array $attributes) => [
            'related_item_type' => 'Book',
            'volume' => null,
            'issue' => null,
            'edition' => '1st',
        ]);
    }
}
