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
