<?php

use App\Models\Publisher;
use Illuminate\Database\Migrations\Migration;

/**
 * Update or create the default GFZ Data Services publisher with full DataCite 4.6 fields.
 *
 * This ensures all installations have the complete publisher metadata
 * including publisherIdentifier, publisherIdentifierScheme, schemeUri, and language.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/publisher/
 */
return new class extends Migration
{
    public function up(): void
    {
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
};
