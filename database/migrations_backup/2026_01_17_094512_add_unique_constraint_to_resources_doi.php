<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add unique constraint to resources.doi column.
 *
 * This ensures that DOIs (including IGSNs) are globally unique in the system.
 * IGSNs (International Generic Sample Numbers) must be unique identifiers,
 * so duplicate uploads should be prevented at the database level.
 *
 * The constraint only applies to non-NULL values, allowing draft resources
 * without DOIs to coexist.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            // Add unique constraint on doi column
            // NULL values are allowed (for drafts), but non-NULL values must be unique
            $table->unique('doi', 'resources_doi_unique');
        });
    }
};
