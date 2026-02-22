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
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (ResourceContributor $contributor): void {
            // Assign default "Other" contributor type via pivot table if none assigned yet
            if (! $contributor->relationLoaded('contributorTypes') || $contributor->contributorTypes->isEmpty()) {
                $otherType = ContributorType::where('slug', 'Other')->first()
                    ?? ContributorType::create([
                        'name' => 'Other',
                        'slug' => 'Other',
                    ]);
                $contributor->contributorTypes()->sync([$otherType->id]);
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'contributorable_type' => Person::class,
            'contributorable_id' => Person::factory(),
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
     * Set the contributor type(s) via the pivot table.
     * Must be called after create(), not during state definition.
     *
     * Usage: $contributor = ResourceContributor::factory()->create();
     *        $contributor->contributorTypes()->sync([$type->id]);
     *
     * @deprecated Use afterCreating() or manual sync instead
     */
    public function withType(ContributorType $type): static
    {
        return $this->afterCreating(function (ResourceContributor $contributor) use ($type): void {
            $contributor->contributorTypes()->sync([$type->id]);
        });
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
