<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RoleSeeder extends Seeder
{
    /** @var array<int, array{name: string, applies_to: string}> */
    public const ROLES = [
        [
            'name' => 'Author',
            'applies_to' => Role::APPLIES_TO_AUTHOR,
        ],
        [
            'name' => 'Contact Person',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Data Collector',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Data Curator',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Data Manager',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Distributor',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Editor',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Hosting Institution',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Producer',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        ],
        [
            'name' => 'Project Leader',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Project Manager',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Project Member',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Registration Agency',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Registration Authority',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Related Person',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Researcher',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Research Group',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Rights Holder',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        ],
        [
            'name' => 'Sponsor',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_INSTITUTION,
        ],
        [
            'name' => 'Supervisor',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Translator',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Work Package Leader',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON,
        ],
        [
            'name' => 'Other',
            'applies_to' => Role::APPLIES_TO_CONTRIBUTOR_PERSON_AND_INSTITUTION,
        ],
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::query()->updateOrCreate(
                ['slug' => Str::slug($role['name'])],
                [
                    'name' => $role['name'],
                    'applies_to' => $role['applies_to'],
                    'is_active_in_ernie' => true,
                    'is_active_in_elmo' => true,
                ],
            );
        }
    }
}
