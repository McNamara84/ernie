<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds doi_prefix column for semantic URL generation.
     * Format: "10.5880/igets.bu.l1.001" (full DOI as stored in resources.doi)
     * NULL for draft resources without DOI.
     *
     * Index considerations:
     * - Composite index (doi_prefix, slug) optimizes DOI-based URL lookups
     * - Draft lookups use (resource_id, slug) which is covered by existing
     *   resource_id foreign key index + this composite index
     * - If draft URL performance becomes an issue, consider adding a
     *   dedicated index: INDEX (resource_id, slug) WHERE doi_prefix IS NULL
     */
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            // DOI prefix for URL generation (e.g., "10.5880/igets.bu.l1.001")
            // NULL for drafts without DOI
            //
            // Length of 255 characters is sufficient for DOI values:
            // - DOI syntax: https://www.doi.org/doi_handbook/2_Numbering.html
            // - DOI prefix: "10." + registrant code (typically 4-10 digits)
            // - DOI suffix: typically short alphanumeric identifiers
            // - GFZ example: "10.5880/igets.bu.l1.001" (24 chars)
            // - Maximum DOI length in practice: rarely exceeds 100 characters
            // - 255 chars provides ample headroom for edge cases
            $table->string('doi_prefix', 255)->nullable()->after('resource_id');

            // Composite index for efficient URL lookups: /{doi_prefix}/{slug}
            $table->index(['doi_prefix', 'slug'], 'landing_pages_url_lookup');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropIndex('landing_pages_url_lookup');
            $table->dropColumn('doi_prefix');
        });
    }
};
