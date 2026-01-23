<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ERNIE Database Schema - DataCite 4.6 Compliant (Squashed)
 *
 * This migration creates the complete database schema for ERNIE,
 * aligned with the DataCite Metadata Schema 4.6 standard.
 *
 * Squashed on: 2026-01-22
 * Includes all migrations from initial schema through 2026-01-21.
 *
 * Column Naming Convention:
 * - Column names use concise, canonical names (e.g., `value` instead of
 *   `description_value`, `place` instead of `geo_location_place`) for brevity.
 * - These names were chosen intentionally from the initial schema design.
 *   No column renaming migrations are needed.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/
 */
return new class extends Migration
{
    public function up(): void
    {
        // =====================================================================
        // LOOKUP TABLES (Type definitions from DataCite controlled vocabularies)
        // =====================================================================

        // Resource Types (DataCite #10)
        Schema::create('resource_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite resourceTypeGeneral value
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->timestamps();
        });

        // Title Types (DataCite #3)
        Schema::create('title_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite titleType value
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->timestamps();
        });

        // Date Types (DataCite #8)
        Schema::create('date_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite dateType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Description Types (DataCite #17)
        Schema::create('description_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite descriptionType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Contributor Types (DataCite #7)
        Schema::create('contributor_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite contributorType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Identifier Types (DataCite #12)
        Schema::create('identifier_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite relatedIdentifierType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Relation Types (DataCite #12)
        Schema::create('relation_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite relationType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Funder Identifier Types (DataCite #19)
        Schema::create('funder_identifier_types', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Display name
            $table->string('slug')->unique();              // DataCite funderIdentifierType value
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Languages (DataCite #9)
        Schema::create('languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 10)->unique();          // ISO 639-1 code (e.g., 'en', 'de')
            $table->string('name');                        // Display name (e.g., 'English')
            // Legacy columns for backward compatibility with tests
            $table->boolean('active')->default(true);
            $table->boolean('elmo_active')->default(true);
            $table->timestamps();
        });

        // Rights / Licenses (DataCite #16)
        Schema::create('rights', function (Blueprint $table): void {
            $table->id();
            $table->string('identifier')->unique();        // SPDX identifier
            $table->string('name');                        // Full name
            $table->string('uri', 512)->nullable();        // License URL
            $table->string('scheme_uri', 512)->nullable(); // e.g., "https://spdx.org/licenses/"
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();
        });

        // Publishers (DataCite #4)
        Schema::create('publishers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Publisher name
            $table->string('identifier', 512)->nullable(); // Publisher identifier (e.g., re3data DOI)
            $table->string('identifier_scheme', 100)->nullable(); // e.g., "re3data"
            $table->string('scheme_uri', 512)->nullable(); // e.g., "https://re3data.org/"
            $table->string('language', 10)->default('en'); // xml:lang
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index('is_default');
        });

        // =====================================================================
        // ENTITY TABLES (Persons and Institutions for Creators/Contributors)
        // =====================================================================

        // Persons (for Creators/Contributors - DataCite #2, #7)
        Schema::create('persons', function (Blueprint $table): void {
            $table->id();
            $table->string('family_name');                 // DataCite familyName
            $table->string('given_name')->nullable();      // DataCite givenName
            $table->string('name_identifier', 512)->nullable()->unique(); // Full ORCID URL
            $table->string('name_identifier_scheme', 50)->nullable();     // "ORCID"
            $table->string('scheme_uri', 512)->nullable(); // "https://orcid.org/"
            $table->timestamp('orcid_verified_at')->nullable();
            $table->timestamps();
        });

        // Institutions (for Creators/Contributors - DataCite #2, #7)
        Schema::create('institutions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');                        // Institution name
            $table->string('name_identifier', 512)->nullable(); // Full ROR URL
            $table->string('name_identifier_scheme', 50)->nullable(); // "ROR"
            $table->string('scheme_uri', 512)->nullable(); // "https://ror.org/"
            $table->timestamps();

            $table->unique(['name', 'name_identifier']);
        });

        // =====================================================================
        // MAIN RESOURCE TABLE
        // =====================================================================

        // Resources (Central entity)
        Schema::create('resources', function (Blueprint $table): void {
            $table->id();

            // DataCite #1: Identifier
            $table->string('doi')->nullable()->unique();   // Unique constraint added
            $table->string('identifier_type', 50)->default('DOI');

            // DataCite #4: Publisher
            $table->foreignId('publisher_id')
                ->nullable()
                ->constrained('publishers')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // DataCite #5: PublicationYear
            $table->unsignedSmallInteger('publication_year')->nullable();

            // DataCite #10: ResourceType
            $table->foreignId('resource_type_id')
                ->constrained('resource_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // DataCite #15: Version
            $table->string('version', 50)->nullable();

            // DataCite #9: Language
            $table->foreignId('language_id')
                ->nullable()
                ->constrained('languages')
                ->cascadeOnUpdate()
                ->nullOnDelete();

            // User tracking
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Indexes for query optimization
            $table->index('publication_year');
            $table->index(['resource_type_id', 'created_at']);
        });

        // =====================================================================
        // RESOURCE RELATIONSHIP TABLES
        // =====================================================================

        // Titles (DataCite #3)
        Schema::create('titles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('title_type_id')
                ->constrained('title_types')               // NOT NULL - always required
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('value', 1000);                 // Title text
            $table->string('language', 10)->nullable();    // xml:lang
            $table->timestamps();

            $table->index('resource_id');
        });

        // Creators (DataCite #2) - Polymorphic to Person or Institution
        Schema::create('resource_creators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            // Polymorphic relationship (Person or Institution)
            $table->string('creatorable_type');
            $table->unsignedBigInteger('creatorable_id');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_contact')->default(false); // Contact person flag
            // Contact information (legacy, for backward compatibility)
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'position']);
            $table->index(['creatorable_type', 'creatorable_id'], 'creators_morph_idx');
        });

        // Contributors (DataCite #7) - Polymorphic to Person or Institution
        Schema::create('resource_contributors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            // Polymorphic relationship (Person or Institution)
            $table->string('contributorable_type');
            $table->unsignedBigInteger('contributorable_id');
            $table->foreignId('contributor_type_id')
                ->constrained('contributor_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
            $table->index(['contributorable_type', 'contributorable_id'], 'contributors_morph_idx');
        });

        // Affiliations (DataCite 2.5, 7.5) - Polymorphic to Creator or Contributor
        Schema::create('affiliations', function (Blueprint $table): void {
            $table->id();
            // Polymorphic relationship (resource_creators or resource_contributors)
            $table->string('affiliatable_type');
            $table->unsignedBigInteger('affiliatable_id');
            $table->text('name');                          // Affiliation name (TEXT for long names)
            $table->string('identifier', 512)->nullable(); // Full ROR URL
            $table->string('identifier_scheme', 50)->nullable(); // "ROR"
            $table->string('scheme_uri', 512)->nullable(); // "https://ror.org/"
            $table->timestamps();

            $table->index(['affiliatable_type', 'affiliatable_id'], 'affiliations_morph_idx');
            $table->index(['identifier_scheme', 'identifier'], 'affiliations_ror_lookup_index');
            // Note: name index is created after the table due to TEXT column limitations
        });

        // Create name index for affiliations table (driver-specific)
        // MySQL/MariaDB requires a prefix length for TEXT columns
        if (DB::getDriverName() === 'sqlite') {
            Schema::table('affiliations', function (Blueprint $table): void {
                $table->index('name', 'idx_affiliations_name');
            });
        } else {
            // MySQL/MariaDB: Use prefix index (191 chars for utf8mb4 compatibility)
            DB::statement('CREATE INDEX idx_affiliations_name ON affiliations (name(191))');
        }

        // Subjects (DataCite #6) - Merged keywords and controlled keywords
        Schema::create('subjects', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('value');                       // Subject text
            $table->string('language', 10)->default('en'); // xml:lang

            // Optional: For controlled vocabularies (GCMD, MSL, etc.)
            $table->string('subject_scheme')->nullable();  // e.g., "GCMD Science Keywords"
            $table->string('scheme_uri', 512)->nullable(); // Scheme URI
            $table->string('value_uri', 512)->nullable();  // Full concept URI
            $table->string('classification_code')->nullable(); // For hierarchical codes

            $table->timestamps();

            $table->index('resource_id');
            $table->index('subject_scheme');
        });

        // Dates (DataCite #8) - Including temporal coverage
        // Uses VARCHAR for flexible ISO 8601 formats: YYYY, YYYY-MM, YYYY-MM-DD
        Schema::create('dates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('date_type_id')
                ->constrained('date_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Flexible date storage (VARCHAR for YYYY, YYYY-MM, YYYY-MM-DD)
            $table->string('date_value', 10)->nullable();  // For single dates
            $table->string('start_date', 10)->nullable();  // For ranges (temporal coverage)
            $table->string('end_date', 10)->nullable();    // For ranges (temporal coverage)
            $table->string('date_information')->nullable(); // Free text for special cases

            $table->timestamps();

            $table->index(['resource_id', 'date_type_id']);
        });

        // Descriptions (DataCite #17)
        Schema::create('descriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('description_type_id')
                ->constrained('description_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->text('value');                         // Description text
            $table->string('language', 10)->nullable();    // xml:lang
            $table->timestamps();

            $table->index('resource_id');
        });

        // GeoLocations (DataCite #18)
        Schema::create('geo_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // 18.3 geoLocationPlace
            $table->text('place')->nullable();             // TEXT for long place names

            // 18.1 geoLocationPoint
            $table->decimal('point_longitude', 11, 8)->nullable();
            $table->decimal('point_latitude', 10, 8)->nullable();

            // Elevation (for IGSN physical samples)
            $table->decimal('elevation', 10, 2)->nullable();
            $table->string('elevation_unit', 50)->nullable();

            // 18.2 geoLocationBox
            $table->decimal('west_bound_longitude', 11, 8)->nullable();
            $table->decimal('east_bound_longitude', 11, 8)->nullable();
            $table->decimal('south_bound_latitude', 10, 8)->nullable();
            $table->decimal('north_bound_latitude', 10, 8)->nullable();

            // 18.4 geoLocationPolygon
            $table->json('polygon_points')->nullable();
            $table->decimal('in_polygon_point_longitude', 11, 8)->nullable();
            $table->decimal('in_polygon_point_latitude', 10, 8)->nullable();

            $table->timestamps();

            $table->index('resource_id');
        });

        // Related Identifiers (DataCite #12)
        Schema::create('related_identifiers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('identifier', 2183);            // The identifier value
            $table->foreignId('identifier_type_id')
                ->constrained('identifier_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->foreignId('relation_type_id')
                ->constrained('relation_types')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            // Optional metadata scheme info
            $table->string('related_metadata_scheme')->nullable();
            $table->string('scheme_uri', 512)->nullable();
            $table->string('scheme_type', 100)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
        });

        // Funding References (DataCite #19)
        Schema::create('funding_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // 19.1 funderName
            $table->string('funder_name');

            // 19.2 funderIdentifier
            $table->string('funder_identifier', 512)->nullable();
            $table->foreignId('funder_identifier_type_id')
                ->nullable()
                ->constrained('funder_identifier_types')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('scheme_uri', 512)->nullable();

            // 19.3 awardNumber
            $table->string('award_number')->nullable();
            $table->string('award_uri', 512)->nullable();

            // 19.4 awardTitle
            $table->text('award_title')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
            $table->index('funder_name');
        });

        // Resource Rights (DataCite #16) - Pivot table
        Schema::create('resource_rights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->foreignId('rights_id')
                ->constrained('rights')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['resource_id', 'rights_id']);
        });

        // Sizes (DataCite #13) - Structure only, not yet used
        Schema::create('sizes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('value');                       // e.g., "15 MB", "100 pages"
            $table->timestamps();

            $table->index('resource_id');
        });

        // Formats (DataCite #14) - Structure only, not yet used
        Schema::create('formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('value');                       // MIME type, e.g., "application/pdf"
            $table->timestamps();

            $table->index('resource_id');
        });

        // =====================================================================
        // APPLICATION-SPECIFIC TABLES
        // =====================================================================

        // Landing Pages
        Schema::create('landing_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->unique()                             // Enforces 1:1 relationship
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('doi_prefix', 255)->nullable(); // DOI for semantic URLs
            $table->string('slug');                    // NOT globally unique - see composite constraints below
            $table->string('template', 50)->default('default_gfz');
            $table->string('ftp_url', 2048)->nullable();
            $table->boolean('is_published')->default(false);
            $table->string('preview_token', 64)->nullable()->unique();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->timestamps();

            $table->index('is_published');
            // Note: preview_token index is implicitly created by unique() constraint
            // Note: (doi_prefix, slug) index is implicitly created by unique() constraint
            $table->unique(['doi_prefix', 'slug'], 'landing_pages_unique_url');
            $table->unique(['resource_id', 'slug'], 'landing_pages_resource_slug_unique');
        });

        // Settings
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            // No timestamps as per application requirement
        });

        // Contact Messages
        Schema::create('contact_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->foreignId('resource_creator_id')
                ->nullable()
                ->constrained('resource_creators')
                ->nullOnDelete();
            $table->boolean('send_to_all')->default(false);
            $table->string('sender_name');
            $table->string('sender_email');
            $table->text('message');
            $table->boolean('copy_to_sender')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['resource_id', 'created_at']);
            $table->index(['ip_address', 'created_at']); // For rate-limiting
        });

        // Thesaurus Settings (GCMD vocabulary configuration)
        Schema::create('thesaurus_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('type')->unique();              // 'science_keywords', 'platforms', 'instruments'
            $table->string('display_name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_elmo_active')->default(true);
            $table->timestamps();
        });

        // Right Resource Type Exclusions (License restrictions per resource type)
        Schema::create('right_resource_type_exclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('right_id')
                ->constrained('rights')
                ->cascadeOnDelete();
            $table->foreignId('resource_type_id')
                ->constrained('resource_types')
                ->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['right_id', 'resource_type_id'], 'unique_exclusion');
        });

        // =====================================================================
        // IGSN TABLES (Physical Sample Management)
        // =====================================================================

        // IGSN Metadata (1:1 with resources for PhysicalObject type)
        Schema::create('igsn_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->unique()                                 // Enforces 1:1 relationship
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->foreignId('parent_resource_id')        // Parent-child hierarchy
                ->nullable()
                ->constrained('resources')
                ->nullOnDelete();

            // Sample properties
            $table->string('sample_type', 100)->nullable();
            $table->string('material', 255)->nullable();
            $table->boolean('is_private')->default(false);
            $table->decimal('size', 12, 4)->nullable();
            $table->string('size_unit', 100)->nullable();

            // Depth information
            $table->decimal('depth_min', 10, 2)->nullable();
            $table->decimal('depth_max', 10, 2)->nullable();
            $table->string('depth_scale', 100)->nullable();

            // Collection details
            $table->text('sample_purpose')->nullable();
            $table->string('collection_method', 255)->nullable();
            $table->text('collection_method_description')->nullable();
            $table->string('collection_date_precision', 20)->nullable();

            // Platform/expedition
            $table->string('cruise_field_program', 255)->nullable();
            $table->string('platform_type', 100)->nullable();
            $table->string('platform_name', 100)->nullable();
            $table->string('platform_description', 255)->nullable();

            // Archive & access
            $table->string('current_archive', 255)->nullable();
            $table->string('current_archive_contact', 255)->nullable();
            $table->string('sample_access', 50)->nullable();
            $table->string('operator', 255)->nullable();

            // Technical details
            $table->string('coordinate_system', 50)->nullable();
            $table->string('user_code', 50)->nullable();
            $table->json('description_json')->nullable();

            // Upload tracking
            $table->string('upload_status', 50)->default('pending');
            $table->text('upload_error_message')->nullable();
            $table->string('csv_filename', 255)->nullable();
            $table->unsignedInteger('csv_row_number')->nullable();

            $table->timestamps();

            $table->index('upload_status');
            $table->index('sample_type');
            $table->index('parent_resource_id');
        });

        // IGSN Classifications (e.g., "Igneous", "Metamorphic", "Sedimentary")
        Schema::create('igsn_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });

        // IGSN Geological Ages (e.g., "Quaternary", "Archean", "Jurassic")
        Schema::create('igsn_geological_ages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });

        // IGSN Geological Units (e.g., "Permian", "Triassic")
        Schema::create('igsn_geological_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });
    }

    public function down(): void
    {
        // Drop in reverse order of creation (respecting foreign keys)

        // IGSN tables
        Schema::dropIfExists('igsn_geological_units');
        Schema::dropIfExists('igsn_geological_ages');
        Schema::dropIfExists('igsn_classifications');
        Schema::dropIfExists('igsn_metadata');

        // Application tables
        Schema::dropIfExists('right_resource_type_exclusions');
        Schema::dropIfExists('thesaurus_settings');
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('landing_pages');

        // Resource relationship tables
        Schema::dropIfExists('formats');
        Schema::dropIfExists('sizes');
        Schema::dropIfExists('resource_rights');
        Schema::dropIfExists('funding_references');
        Schema::dropIfExists('related_identifiers');
        Schema::dropIfExists('geo_locations');
        Schema::dropIfExists('descriptions');
        Schema::dropIfExists('dates');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('affiliations');
        Schema::dropIfExists('resource_contributors');
        Schema::dropIfExists('resource_creators');
        Schema::dropIfExists('titles');

        // Main resource table
        Schema::dropIfExists('resources');

        // Entity tables
        Schema::dropIfExists('institutions');
        Schema::dropIfExists('persons');

        // Lookup tables
        Schema::dropIfExists('publishers');
        Schema::dropIfExists('rights');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('funder_identifier_types');
        Schema::dropIfExists('relation_types');
        Schema::dropIfExists('identifier_types');
        Schema::dropIfExists('contributor_types');
        Schema::dropIfExists('description_types');
        Schema::dropIfExists('date_types');
        Schema::dropIfExists('title_types');
        Schema::dropIfExists('resource_types');
    }
};
