<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add indexes for polymorphic relationships to optimize resource queries.
     * These indexes significantly improve performance when eager loading
     * creators, contributors, and their affiliations.
     */
    public function up(): void
    {
        // Note: Indexes for resource_creators and resource_contributors already exist
        // in the main schema migration (0001_01_01_000003_create_ernie_schema.php)
        // This migration adds additional optimizations where needed.

        // Check index existence outside the callback for proper conditional execution
        if (! Schema::hasIndex('affiliations', 'idx_affiliations_name')) {
            Schema::table('affiliations', function (Blueprint $table) {
                // Index for affiliation name lookups (for filtering/searching)
                // The polymorphic index already exists in the main schema
                $table->index('name', 'idx_affiliations_name');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Check index existence outside the callback for proper conditional execution
        if (Schema::hasIndex('affiliations', 'idx_affiliations_name')) {
            Schema::table('affiliations', function (Blueprint $table) {
                $table->dropIndex('idx_affiliations_name');
            });
        }
    }
};
