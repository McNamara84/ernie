<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceCreator>
 */
class ResourceCreatorFactory extends Factory
{
    protected $model = ResourceCreator::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'resource_id' => Resource::factory(),
            'creatorable_type' => Person::class,
            'creatorable_id' => Person::factory(),
            'position' => 1,
            'email' => null,
            'website' => null,
        ];
    }

    /**
     * Indicate that the creator is a person.
     */
    public function forPerson(?Person $person = null): static
    {
        return $this->state(function (array $attributes) use ($person) {
            $person = $person ?? Person::factory()->create();

            return [
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
            ];
        });
    }

    /**
     * Indicate that the creator is an institution.
     */
    public function forInstitution(?Institution $institution = null): static
    {
        return $this->state(function (array $attributes) use ($institution) {
            $institution = $institution ?? Institution::factory()->create();

            return [
                'creatorable_type' => Institution::class,
                'creatorable_id' => $institution->id,
            ];
        });
    }

    /**
     * Set the position.
     */
    public function position(int $position): static
    {
        return $this->state(fn (array $attributes) => [
            'position' => $position,
        ]);
    }
}
