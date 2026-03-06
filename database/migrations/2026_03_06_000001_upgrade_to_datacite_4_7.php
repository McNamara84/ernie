<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\RelationType;
use App\Models\ResourceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Upgrade schema from DataCite 4.6 to 4.7.
 *
 * Schema changes:
 * - Add 'Poster' and 'Presentation' to resourceTypeGeneral
 * - Add 'RAiD' and 'SWHID' to relatedIdentifierType
 * - Add 'Other' to relationType
 * - Add 'relation_type_information' column to related_identifiers table
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/introduction/version-update/#schema-changes
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Add relationTypeInformation column to related_identifiers
        Schema::table('related_identifiers', function (Blueprint $table): void {
            $table->string('relation_type_information')
                ->nullable()
                ->after('relation_type_id')
                ->comment('Free text describing the relationship (DataCite 4.7, property 12.g)');
        });

        // 2. Seed new resourceTypeGeneral values
        foreach (['Poster', 'Presentation'] as $name) {
            ResourceType::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name],
            );
        }

        // Update descriptions for the new resource types
        ResourceType::where('name', 'Poster')
            ->update(['description' => 'A display poster, typically containing text with illustrative figures and/or tables, usually reporting research results or proposing hypotheses, submitted for acceptance to and/or presented at a conference, seminar, symposium, workshop or similar event.']);

        ResourceType::where('name', 'Presentation')
            ->update(['description' => 'A set of slides containing text, tables or figures, designed to communicate ideas or research results, for viewing by an audience at a conference, symposium, seminar, lecture, workshop or other event.']);

        // 3. Seed new relatedIdentifierType values
        foreach ([
            ['name' => 'RAiD', 'slug' => 'RAiD'],
            ['name' => 'SWHID', 'slug' => 'SWHID'],
        ] as $type) {
            IdentifierType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']],
            );
        }

        // 4. Seed new relationType value
        RelationType::firstOrCreate(
            ['slug' => 'Other'],
            ['name' => 'Other'],
        );
    }
};
