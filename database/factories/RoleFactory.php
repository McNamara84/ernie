<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Role>
 */
class RoleFactory extends Factory
{
    protected $model = Role::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $roles = [
            ['name' => 'Author', 'slug' => 'author', 'applies_to' => Role::APPLIES_TO_AUTHOR],
            ['name' => 'Editor', 'slug' => 'editor', 'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON],
            ['name' => 'Reviewer', 'slug' => 'reviewer', 'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON],
            ['name' => 'Contact Person', 'slug' => 'contact-person', 'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON],
            ['name' => 'Data Collector', 'slug' => 'data-collector', 'applies_to' => Role::APPLIES_TO_AUTHOR],
            ['name' => 'Data Manager', 'slug' => 'data-manager', 'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON],
        ];

        static $index = 0;
        $role = $roles[$index % count($roles)];
        $index++;

        return [
            'name' => $role['name'],
            'slug' => $role['slug'],
            'applies_to' => $role['applies_to'],
        ];
    }
}
