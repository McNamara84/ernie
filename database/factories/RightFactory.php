<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Right;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Right>
 */
class RightFactory extends Factory
{
    protected $model = Right::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $licenses = [
            ['identifier' => 'CC-BY-4.0', 'name' => 'Creative Commons Attribution 4.0 International', 'uri' => 'https://creativecommons.org/licenses/by/4.0/'],
            ['identifier' => 'CC-BY-SA-4.0', 'name' => 'Creative Commons Attribution Share Alike 4.0 International', 'uri' => 'https://creativecommons.org/licenses/by-sa/4.0/'],
            ['identifier' => 'CC0-1.0', 'name' => 'Creative Commons Zero v1.0 Universal', 'uri' => 'https://creativecommons.org/publicdomain/zero/1.0/'],
            ['identifier' => 'MIT', 'name' => 'MIT License', 'uri' => 'https://opensource.org/licenses/MIT'],
            ['identifier' => 'Apache-2.0', 'name' => 'Apache License 2.0', 'uri' => 'https://www.apache.org/licenses/LICENSE-2.0'],
        ];

        $license = $this->faker->randomElement($licenses);

        return [
            'identifier' => $license['identifier'].'-'.$this->faker->unique()->randomNumber(5),
            'name' => $license['name'],
            'uri' => $license['uri'],
            'scheme_uri' => 'https://spdx.org/licenses/',
            'is_active' => true,
            'is_elmo_active' => true,
            'usage_count' => 0,
        ];
    }

    /**
     * Create a CC-BY-4.0 license.
     */
    public function ccBy4(): static
    {
        return $this->state(fn (array $attributes) => [
            'identifier' => 'CC-BY-4.0',
            'name' => 'Creative Commons Attribution 4.0 International',
            'uri' => 'https://creativecommons.org/licenses/by/4.0/',
        ]);
    }

    /**
     * Create a CC0 license.
     */
    public function cc0(): static
    {
        return $this->state(fn (array $attributes) => [
            'identifier' => 'CC0-1.0',
            'name' => 'Creative Commons Zero v1.0 Universal',
            'uri' => 'https://creativecommons.org/publicdomain/zero/1.0/',
        ]);
    }

    /**
     * Create a MIT license.
     */
    public function mit(): static
    {
        return $this->state(fn (array $attributes) => [
            'identifier' => 'MIT',
            'name' => 'MIT License',
            'uri' => 'https://opensource.org/licenses/MIT',
        ]);
    }
}
