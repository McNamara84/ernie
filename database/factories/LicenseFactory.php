<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\License;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<License>
 */
class LicenseFactory extends Factory
{
    protected $model = License::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $licenses = [
            ['identifier' => 'cc-by-4', 'name' => 'Creative Commons Attribution 4.0'],
            ['identifier' => 'cc-by-sa-4', 'name' => 'Creative Commons Attribution-ShareAlike 4.0'],
            ['identifier' => 'cc-by-nc-4', 'name' => 'Creative Commons Attribution-NonCommercial 4.0'],
            ['identifier' => 'cc0-1', 'name' => 'Creative Commons Zero 1.0'],
            ['identifier' => 'mit', 'name' => 'MIT License'],
            ['identifier' => 'apache-2', 'name' => 'Apache License 2.0'],
        ];

        static $index = 0;
        $license = $licenses[$index % count($licenses)];
        $index++;

        return [
            'identifier' => $license['identifier'],
            'name' => $license['name'],
            'active' => true,
            'elmo_active' => true,
        ];
    }
}
