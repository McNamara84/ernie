<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Institution>
 */
class InstitutionFactory extends Factory
{
    protected $model = Institution::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'name_identifier' => null,
            'name_identifier_scheme' => null,
            'scheme_uri' => null,
        ];
    }

    /**
     * Indicate that the institution has a ROR identifier.
     */
    public function withRor(?string $ror = null): static
    {
        return $this->state(fn (array $attributes) => [
            'name_identifier' => $ror ?? 'https://ror.org/'.fake()->regexify('[0-9a-z]{9}'),
            'name_identifier_scheme' => 'ROR',
            'scheme_uri' => 'https://ror.org/',
        ]);
    }
}
