<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds DataCite Schema 4.6 controlled list values to existing databases.
 *
 * New values added:
 * - dateType: Coverage
 * - contributorType: Translator
 * - relatedIdentifierType: CSTR, RRID
 * - relationType: HasTranslation, IsTranslationOf
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add Coverage dateType (inactive by default - admin must enable)
        DB::table('date_types')->insertOrIgnore([
            'name' => 'Coverage',
            'slug' => 'Coverage',
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add Translator contributorType
        DB::table('contributor_types')->insertOrIgnore([
            'name' => 'Translator',
            'slug' => 'Translator',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add CSTR identifierType
        DB::table('identifier_types')->insertOrIgnore([
            'name' => 'CSTR',
            'slug' => 'CSTR',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add RRID identifierType
        DB::table('identifier_types')->insertOrIgnore([
            'name' => 'RRID',
            'slug' => 'RRID',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add HasTranslation relationType
        DB::table('relation_types')->insertOrIgnore([
            'name' => 'Has Translation',
            'slug' => 'HasTranslation',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Add IsTranslationOf relationType
        DB::table('relation_types')->insertOrIgnore([
            'name' => 'Is Translation Of',
            'slug' => 'IsTranslationOf',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
