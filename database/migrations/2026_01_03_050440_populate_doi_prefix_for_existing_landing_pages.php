<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Populates doi_prefix for existing landing pages from their associated resource's DOI.
     * This ensures existing published landing pages continue to work with DOI-based URLs
     * after the schema change.
     *
     * Impact:
     * - Published landing pages with DOI: URL changes from /datasets/{id} to /{doi}/{slug}
     * - Published landing pages without DOI: URL remains /draft-{id}/{slug}
     * - The legacy /datasets/{id} route provides 301 redirects for old bookmarks
     */
    public function up(): void
    {
        // Use database-agnostic subquery syntax that works with both MySQL and SQLite.
        // SQLite doesn't support UPDATE ... JOIN, so we use a subquery instead.
        $updated = DB::update('
            UPDATE landing_pages
            SET doi_prefix = (
                SELECT doi FROM resources WHERE resources.id = landing_pages.resource_id
            )
            WHERE doi_prefix IS NULL
              AND EXISTS (
                SELECT 1 FROM resources 
                WHERE resources.id = landing_pages.resource_id 
                  AND resources.doi IS NOT NULL
              )
        ');

        Log::info("DataMigration: Updated {$updated} landing pages with doi_prefix from resources");
    }

    /**
     * Reverse the migrations.
     *
     * Sets doi_prefix back to NULL for all landing pages.
     * This will cause all landing pages to use draft-style URLs.
     */
    public function down(): void
    {
        DB::update('UPDATE landing_pages SET doi_prefix = NULL WHERE doi_prefix IS NOT NULL');

        Log::info('DataMigration: Cleared all doi_prefix values from landing pages');
    }
};
