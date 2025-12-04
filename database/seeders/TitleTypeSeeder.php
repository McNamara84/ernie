<?php

namespace Database\Seeders;

use App\Models\TitleType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Title Types (DataCite #3)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/title/
 */
class TitleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite titleType controlled values
        $types = [
            ['name' => 'Main Title', 'slug' => 'MainTitle'],
            ['name' => 'Alternative Title', 'slug' => 'AlternativeTitle'],
            ['name' => 'Subtitle', 'slug' => 'Subtitle'],
            ['name' => 'Translated Title', 'slug' => 'TranslatedTitle'],
            ['name' => 'Other', 'slug' => 'Other'],
        ];

        foreach ($types as $type) {
            TitleType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
