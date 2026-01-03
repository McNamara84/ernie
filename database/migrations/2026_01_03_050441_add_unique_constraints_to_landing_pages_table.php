<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds unique constraints to prevent slug collisions:
     * 1. (doi_prefix, slug) - Ensures unique slugs per DOI for published pages
     * 2. (resource_id, slug) - Ensures unique slugs per resource for draft pages
     *
     * These constraints work together to ensure URL uniqueness:
     * - Published pages: /{doi_prefix}/{slug} - doi_prefix+slug must be unique
     * - Draft pages: /draft-{resource_id}/{slug} - resource_id+slug must be unique
     *
     * Note: MySQL doesn't support partial unique indexes (WHERE clause), so we use
     * composite indexes that include the nullable doi_prefix column. In MySQL,
     * NULL values in unique indexes are treated as distinct, so multiple rows with
     * NULL doi_prefix but same slug are allowed - this is the desired behavior for
     * resources without DOI that share common titles.
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
