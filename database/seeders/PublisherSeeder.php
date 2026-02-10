<?php

namespace Database\Seeders;

use App\Models\Publisher;
use Illuminate\Database\Seeder;

/**
 * Seeder for Publishers (DataCite #4)
 *
 * Seeds the default GFZ Data Services publisher.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/publisher/
 */
class PublisherSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // GFZ Data Services as default publisher
        // Use updateOrCreate to ensure existing records get updated with
        // all identifier fields (fixes incomplete publishers from older seeds)
        Publisher::updateOrCreate(
            ['name' => 'GFZ Data Services'],
            [
                'identifier' => 'https://doi.org/10.17616/R3VQ0S',
                'identifier_scheme' => 're3data',
                'scheme_uri' => 'https://re3data.org/',
                'language' => 'en',
                'is_default' => true,
            ]
        );
    }
}
