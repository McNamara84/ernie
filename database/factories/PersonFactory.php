<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'given_name' => $this->faker->firstName(),
            'family_name' => $this->faker->lastName(),
            'name_identifier' => null,
            'name_identifier_scheme' => null,
            'scheme_uri' => null,
        ];
    }

    /**
     * Indicate that the person has an ORCID.
     */
    public function withOrcid(?string $orcid = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name_identifier' => $orcid ?? 'https://orcid.org/'.$this->faker->regexify('[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{3}[0-9X]'),
            'name_identifier_scheme' => 'ORCID',
            'scheme_uri' => 'https://orcid.org/',
        ]);
    }
}
