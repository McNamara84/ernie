<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            // Add unique constraint to enforce distinct positions per landing page.
            // Must be created BEFORE dropping the non-unique index so the FK on
            // landing_page_id remains satisfied by the new unique index.
            $table->unique(['landing_page_id', 'position']);
        });

        Schema::table('landing_page_links', function (Blueprint $table): void {
            // Now safe to drop the redundant non-unique index
            $table->dropIndex(['landing_page_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            // Restore the non-unique index before dropping the unique constraint
            $table->index(['landing_page_id', 'position']);
        });

        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->dropUnique(['landing_page_id', 'position']);
        });
    }
};
