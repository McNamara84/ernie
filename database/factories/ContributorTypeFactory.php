<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContributorCategory;
use App\Models\ContributorType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContributorType>
 */
class ContributorTypeFactory extends Factory
{
    protected $model = ContributorType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = [
            ['name' => 'Contact Person', 'slug' => 'ContactPerson'],
            ['name' => 'Data Collector', 'slug' => 'DataCollector'],
            ['name' => 'Data Curator', 'slug' => 'DataCurator'],
            ['name' => 'Data Manager', 'slug' => 'DataManager'],
            ['name' => 'Editor', 'slug' => 'Editor'],
            ['name' => 'Project Leader', 'slug' => 'ProjectLeader'],
            ['name' => 'Researcher', 'slug' => 'Researcher'],
        ];

        $type = fake()->randomElement($types);

        return [
            'name' => $type['name'],
            'slug' => $type['slug'].'-'.fake()->unique()->randomNumber(5),
            'category' => ContributorCategory::PERSON,
            'is_active' => true,
            'is_elmo_active' => true,
        ];
    }

    /**
     * Create a Contact Person type.
     */
    public function contactPerson(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Contact Person',
            'slug' => 'ContactPerson',
            'category' => ContributorCategory::PERSON,
        ]);
    }

    /**
     * Create a Data Curator type.
     */
    public function dataCurator(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Data Curator',
            'slug' => 'DataCurator',
            'category' => ContributorCategory::PERSON,
        ]);
    }

    /**
     * Create a Researcher type.
     */
    public function researcher(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Researcher',
            'slug' => 'Researcher',
            'category' => ContributorCategory::PERSON,
        ]);
    }

    /**
     * Set the category to institution.
     */
    public function institution(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => ContributorCategory::INSTITUTION,
        ]);
    }

    /**
     * Set the category to both.
     */
    public function both(): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => ContributorCategory::BOTH,
        ]);
    }

    /**
     * Set the type as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Set the type as ELMO inactive.
     */
    public function elmoInactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_elmo_active' => false,
        ]);
    }
}
