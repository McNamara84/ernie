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
        // Use raw query for efficiency on large datasets.
        // This updates all landing pages where the associated resource has a DOI.
        $updated = DB::update('
            UPDATE landing_pages lp
            INNER JOIN resources r ON lp.resource_id = r.id
            SET lp.doi_prefix = r.doi
            WHERE r.doi IS NOT NULL
              AND lp.doi_prefix IS NULL
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
