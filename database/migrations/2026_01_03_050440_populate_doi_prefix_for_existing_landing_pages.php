<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * MIGRATION ORDER: This is migration 2 of 3 for semantic URL support.
     * Prerequisites: Migration 050439 must have run first (adds doi_prefix column).
     * Next: Migration 050441 adds unique constraints after data is populated.
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
    /**
     * DOI format pattern for validation.
     * Format: 10.N+/suffix where N+ is one or more digits (registrant code) and suffix is alphanumeric.
     *
     * Note: We use \d+ (one or more digits) instead of \d{4,} because valid DOI registrant
     * codes can have varying lengths. While most registrant codes are 4-5 digits, shorter
     * codes are valid according to the DOI handbook. Examples:
     * - 10.1000/xyz (4 digits - common)
     * - 10.12345/xyz (5 digits - common)
     * - 10.123/xyz (3 digits - rare but valid)
     */
    private const DOI_PATTERN = '/^10\.\d+\/.+$/';

    public function up(): void
    {
        // First, identify and log any malformed DOIs that will be migrated.
        // This helps operators identify data quality issues without blocking migration.
        $malformedDois = DB::table('resources')
            ->join('landing_pages', 'resources.id', '=', 'landing_pages.resource_id')
            ->whereNotNull('resources.doi')
            ->whereNull('landing_pages.doi_prefix')
            ->get(['resources.id', 'resources.doi']);

        foreach ($malformedDois as $row) {
            if (!preg_match(self::DOI_PATTERN, $row->doi)) {
                // Log as INFO (not warning) since this is informational only.
                // The DOI will be migrated successfully - we're just noting the non-standard format
                // for operators who may want to review/correct these DOIs later.
                Log::info(
                    'DataMigration: Non-standard DOI format detected (will be migrated as-is)',
                    ['resource_id' => $row->id, 'doi' => $row->doi]
                );
            }
        }

        // Use database-agnostic subquery syntax that works with both MySQL and SQLite.
        // SQLite doesn't support UPDATE ... JOIN, so we use a subquery instead.
        //
        // Edge case handling: If there were multiple landing pages per resource_id,
        // all of them would be updated with the same DOI. In practice, this cannot
        // occur because the landing_pages table is designed with a foreign key
        // constraint on resource_id (from the original schema migration).
        // Additionally, after this migration runs, the next migration
        // (2026_01_03_050441_add_unique_constraints_to_landing_pages_table) adds
        // an explicit unique constraint on resource_id, making this a database-level
        // guarantee. For the duration of this migration, the application logic
        // ensures one landing page per resource.
        //
        // Note: We migrate all DOIs including malformed ones to maintain data consistency.
        // Validation/correction should happen at the application layer, not during migration.
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
