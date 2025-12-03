<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DateTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds all standard DataCite date types.
     * Note: 'created' and 'updated' are auto-managed by the system and not included here.
     */
    public function run(): void
    {
        $dateTypes = [
            [
                'name' => 'Accepted',
                'slug' => 'accepted',
                'description' => 'The date that the publisher accepted the resource into their system. To indicate the start of an embargo period, use Accepted or Submitted.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Available',
                'slug' => 'available',
                'description' => 'The date the resource is made publicly available. May be a range. To indicate the end of an embargo period, use Available.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Copyrighted',
                'slug' => 'copyrighted',
                'description' => 'The specific, documented date at which the resource receives a copyrighted status, if applicable.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Collected',
                'slug' => 'collected',
                'description' => 'The date or date range in which the resource content was collected. To indicate precise or particular timeframes in which research was conducted.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Issued',
                'slug' => 'issued',
                'description' => 'The date that the resource is published or distributed, e.g., to a data centre.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Submitted',
                'slug' => 'submitted',
                'description' => 'The date the creator submits the resource to the publisher. This could be different from Accepted if the publisher then applies a selection process. Recommended for discovery. To indicate the start of an embargo period, use Submitted or Accepted.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Valid',
                'slug' => 'valid',
                'description' => 'The date or date range during which the dataset or resource is accurate.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Withdrawn',
                'slug' => 'withdrawn',
                'description' => 'The date the resource is removed. It is good practice to include a Description that indicates the reason for the retraction or withdrawal.',
                'active' => true,
                'elmo_active' => false,
            ],
            [
                'name' => 'Other',
                'slug' => 'other',
                'description' => 'Other date that does not fit into an existing category.',
                'active' => true,
                'elmo_active' => false,
            ],
        ];

        $now = now();

        foreach ($dateTypes as $dateType) {
            DB::table('date_types')->updateOrInsert(
                ['slug' => $dateType['slug']],
                array_merge($dateType, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])
            );
        }
    }
}
