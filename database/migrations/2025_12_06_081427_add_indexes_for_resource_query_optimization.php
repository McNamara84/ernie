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
        Schema::table('resource_creators', function (Blueprint $table) {
            // Composite index for ordering creators by resource and position
            $table->index(['resource_id', 'position'], 'idx_creators_resource_position');
            
            // Composite index for polymorphic relationship lookups
            $table->index(['creatorable_type', 'creatorable_id'], 'idx_creators_polymorphic');
        });

        Schema::table('resource_contributors', function (Blueprint $table) {
            // Composite index for ordering contributors by resource and position
            $table->index(['resource_id', 'position'], 'idx_contributors_resource_position');
            
            // Composite index for polymorphic relationship lookups
            $table->index(['contributorable_type', 'contributorable_id'], 'idx_contributors_polymorphic');
        });

        Schema::table('affiliations', function (Blueprint $table) {
            // Composite index for polymorphic relationship lookups
            $table->index(['affiliatable_type', 'affiliatable_id'], 'idx_affiliations_polymorphic');
            
            // Index for institution lookups
            $table->index('institution_id', 'idx_affiliations_institution');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resource_creators', function (Blueprint $table) {
            $table->dropIndex('idx_creators_resource_position');
            $table->dropIndex('idx_creators_polymorphic');
        });

        Schema::table('resource_contributors', function (Blueprint $table) {
            $table->dropIndex('idx_contributors_resource_position');
            $table->dropIndex('idx_contributors_polymorphic');
        });

        Schema::table('affiliations', function (Blueprint $table) {
            $table->dropIndex('idx_affiliations_polymorphic');
            $table->dropIndex('idx_affiliations_institution');
        });
    }
};
