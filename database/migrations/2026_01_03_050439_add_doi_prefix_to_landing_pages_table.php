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
