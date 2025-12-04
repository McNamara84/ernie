<?php

namespace Database\Seeders;

use App\Models\IdentifierType;
use Illuminate\Database\Seeder;

/**
 * Seeder for Identifier Types (DataCite #12)
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/relatedidentifier/
 */
class IdentifierTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // DataCite relatedIdentifierType controlled values
        $types = [
            ['name' => 'ARK', 'slug' => 'ARK'],
            ['name' => 'arXiv', 'slug' => 'arXiv'],
            ['name' => 'bibcode', 'slug' => 'bibcode'],
            ['name' => 'DOI', 'slug' => 'DOI'],
            ['name' => 'EAN13', 'slug' => 'EAN13'],
            ['name' => 'EISSN', 'slug' => 'EISSN'],
            ['name' => 'Handle', 'slug' => 'Handle'],
            ['name' => 'IGSN', 'slug' => 'IGSN'],
            ['name' => 'ISBN', 'slug' => 'ISBN'],
            ['name' => 'ISSN', 'slug' => 'ISSN'],
            ['name' => 'ISTC', 'slug' => 'ISTC'],
            ['name' => 'LISSN', 'slug' => 'LISSN'],
            ['name' => 'LSID', 'slug' => 'LSID'],
            ['name' => 'PMID', 'slug' => 'PMID'],
            ['name' => 'PURL', 'slug' => 'PURL'],
            ['name' => 'UPC', 'slug' => 'UPC'],
            ['name' => 'URL', 'slug' => 'URL'],
            ['name' => 'URN', 'slug' => 'URN'],
            ['name' => 'w3id', 'slug' => 'w3id'],
        ];

        foreach ($types as $type) {
            IdentifierType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
