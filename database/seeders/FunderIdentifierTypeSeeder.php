<?php

namespace Database\Seeders;

use App\Models\FunderIdentifierType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Funder Identifier Types (DataCite #19)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/fundingreference/
 */
class FunderIdentifierTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite funderIdentifierType controlled values
        $types = [
            ['name' => 'Crossref Funder ID', 'slug' => 'Crossref Funder ID'],
            ['name' => 'GRID', 'slug' => 'GRID'],
            ['name' => 'ISNI', 'slug' => 'ISNI'],
            ['name' => 'ROR', 'slug' => 'ROR'],
            ['name' => 'Other', 'slug' => 'Other'],
        ];

        foreach ($types as $type) {
            FunderIdentifierType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
