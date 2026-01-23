<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MIGRATION ORDER: This is migration 3 of 3 for semantic URL support.
     * Prerequisites: Migrations 050439 and 050440 must have run first.
     * - 050439 adds the doi_prefix column
     * - 050440 populates doi_prefix from existing resources
     * - This migration (050441) adds unique constraints
     *
     * The unique constraints are added AFTER data population to avoid
     * constraint violations during the UPDATE in migration 050440.
     *
     * Adds unique constraints to prevent slug collisions:
     * 1. (doi_prefix, slug) - Ensures unique slugs per DOI for published pages
     * 2. (resource_id, slug) - Ensures unique slugs per resource for draft pages
     *
     * These constraints work together to ensure URL uniqueness:
     * - Published pages: /{doi_prefix}/{slug} - doi_prefix+slug must be unique
     * - Draft pages: /draft-{resource_id}/{slug} - resource_id+slug must be unique
     *
     * NULL handling in unique indexes (database-specific behavior):
     *
     * This migration relies on database-specific NULL handling in unique indexes:
     * - MySQL/MariaDB: NULL values are treated as distinct (multiple NULLs allowed) ✓
     * - SQLite: NULL values are treated as distinct (same as MySQL) ✓
     * - PostgreSQL (14+): NULLs are distinct by default, but NULLS NOT DISTINCT can change this
     * - SQL Server: NULL is treated as a value (only one NULL allowed per unique index)
     *
     * For this application, we want multiple rows with NULL doi_prefix and same slug
     * to be allowed (drafts can share slugs). This behavior works correctly on
     * MySQL, MariaDB, and SQLite which are the ONLY supported databases for ERNIE.
     *
     * IMPORTANT: If deploying to PostgreSQL or SQL Server, this migration needs modification:
     * - PostgreSQL: Works by default, but verify NULLs are distinct in your version
     * - SQL Server: Requires a filtered unique index excluding NULLs
     *
     * Verification query (run after migration to confirm expected behavior):
     * ```sql
     * -- This should succeed on MySQL/MariaDB/SQLite (inserts two NULLs with same slug)
     * INSERT INTO landing_pages (resource_id, doi_prefix, slug) VALUES (1, NULL, 'test');
     * INSERT INTO landing_pages (resource_id, doi_prefix, slug) VALUES (2, NULL, 'test');
     * -- Clean up: DELETE FROM landing_pages WHERE slug = 'test';
     * ```
     *
     * @see tests/pest/Feature/LandingPages/LandingPageTest.php for NULL uniqueness tests
     */
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            // Unique constraint for DOI-based URLs: /{doi_prefix}/{slug}
            // Multiple NULL doi_prefix with same slug is allowed (for drafts)
            $table->unique(['doi_prefix', 'slug'], 'landing_pages_doi_slug_unique');

            // Unique constraint for draft URLs: /draft-{resource_id}/{slug}
            // Since each resource has only one landing page (enforced by resource_id unique),
            // this also prevents any slug collision per resource.
            $table->unique(['resource_id', 'slug'], 'landing_pages_resource_slug_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropUnique('landing_pages_doi_slug_unique');
            $table->dropUnique('landing_pages_resource_slug_unique');
        });
    }
};
