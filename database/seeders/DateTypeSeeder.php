<?php

namespace Database\Seeders;

use App\Models\DateType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Date Types (DataCite #8)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/date/
 */
class DateTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Seeds all standard DataCite date types.
     */
    public function run(): void
    {
        // DataCite dateType controlled values
        // Note: Coverage (Schema 4.6) is inactive by default - admin must enable
        $types = [
            ['name' => 'Accepted', 'slug' => 'Accepted'],
            ['name' => 'Available', 'slug' => 'Available'],
            ['name' => 'Copyrighted', 'slug' => 'Copyrighted'],
            ['name' => 'Collected', 'slug' => 'Collected'],
            ['name' => 'Coverage', 'slug' => 'Coverage', 'is_active' => false],
            ['name' => 'Created', 'slug' => 'Created'],
            ['name' => 'Issued', 'slug' => 'Issued'],
            ['name' => 'Submitted', 'slug' => 'Submitted'],
            ['name' => 'Updated', 'slug' => 'Updated'],
            ['name' => 'Valid', 'slug' => 'Valid'],
            ['name' => 'Withdrawn', 'slug' => 'Withdrawn'],
            ['name' => 'Other', 'slug' => 'Other'],
        ];

        foreach ($types as $type) {
            DateType::firstOrCreate(
                ['slug' => $type['slug']],
                [
                    'name' => $type['name'],
                    'is_active' => $type['is_active'] ?? true,
                ]
            );
        }
    }
}
