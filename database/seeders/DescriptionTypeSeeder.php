<?php

namespace Database\Seeders;

use App\Models\DescriptionType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Description Types (DataCite #17)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/description/
 */
class DescriptionTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite descriptionType controlled values
        $types = [
            ['name' => 'Abstract', 'slug' => 'Abstract'],
            ['name' => 'Methods', 'slug' => 'Methods'],
            ['name' => 'Series Information', 'slug' => 'SeriesInformation'],
            ['name' => 'Table of Contents', 'slug' => 'TableOfContents'],
            ['name' => 'Technical Info', 'slug' => 'TechnicalInfo'],
            ['name' => 'Other', 'slug' => 'Other'],
        ];

        foreach ($types as $type) {
            DescriptionType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
