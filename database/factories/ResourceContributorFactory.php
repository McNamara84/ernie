<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceContributor>
 */
class ResourceContributorFactory extends Factory
{
    protected $model = ResourceContributor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Get or create a default contributor type
        $contributorType = ContributorType::where('slug', 'other')->first()
            ?? ContributorType::create([
                'name' => 'Other',
                'slug' => 'other',
            ]);

        return [
            'resource_id' => Resource::factory(),
            'contributorable_type' => Person::class,
            'contributorable_id' => Person::factory(),
            'contributor_type_id' => $contributorType->id,
            'position' => 1,
        ];
    }

    /**
     * Indicate that the contributor is a person.
     */
    public function forPerson(?Person $person = null): static
    {
        return $this->state(function (array $attributes) use ($person) {
            $person = $person ?? Person::factory()->create();

            return [
                'contributorable_type' => Person::class,
                'contributorable_id' => $person->id,
            ];
        });
    }

    /**
     * Indicate that the contributor is an institution.
     */
    public function forInstitution(?Institution $institution = null): static
    {
        return $this->state(function (array $attributes) use ($institution) {
            $institution = $institution ?? Institution::factory()->create();

            return [
                'contributorable_type' => Institution::class,
                'contributorable_id' => $institution->id,
            ];
        });
    }

    /**
     * Set the contributor type.
     */
    public function withType(ContributorType $type): static
    {
        return $this->state(fn (array $attributes) => [
            'contributor_type_id' => $type->id,
        ]);
    }

    /**
     * Set the position.
     */
    public function atPosition(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }
}
