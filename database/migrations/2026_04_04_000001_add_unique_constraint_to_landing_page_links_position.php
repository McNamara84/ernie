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
            // The existing non-unique index is kept because MySQL requires an index
            // on the FK column; MySQL may auto-drop it once the unique index covers it.
            $table->unique(['landing_page_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_page_links', function (Blueprint $table): void {
            $table->dropUnique(['landing_page_id', 'position']);
        });
    }
};
