<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LandingPageDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LandingPageDomain>
 */
class LandingPageDomainFactory extends Factory
{
    protected $model = LandingPageDomain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => 'https://' . fake()->unique()->domainName() . '/',
        ];
    }

    /**
     * Create a domain with a specific URL.
     */
    public function withDomain(string $domain): static
    {
        return $this->state(fn (): array => [
            'domain' => $domain,
        ]);
    }
}
