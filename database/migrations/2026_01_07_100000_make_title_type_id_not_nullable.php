<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make title_type_id NOT NULL in the titles table.
 *
 * This migration:
 * 1. Finds or creates the MainTitle TitleType record
 * 2. Updates any existing titles with NULL title_type_id to reference MainTitle
 * 3. Alters the column to be NOT NULL
 *
 * This aligns the database schema with the application logic that now requires
 * all titles (including main titles) to have a TitleType reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1: Ensure MainTitle TitleType exists
        $mainTitleId = DB::table('title_types')
            ->where('slug', 'MainTitle')
            ->value('id');

        if ($mainTitleId === null) {
            $mainTitleId = DB::table('title_types')->insertGetId([
                'name' => 'Main Title',
                'slug' => 'MainTitle',
                'is_active' => true,
                'is_elmo_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Step 2: Update any existing titles with NULL title_type_id
        DB::table('titles')
            ->whereNull('title_type_id')
            ->update(['title_type_id' => $mainTitleId]);

        // Step 3: Make the column NOT NULL
        Schema::table('titles', function (Blueprint $table): void {
            $table->foreignId('title_type_id')
                ->nullable(false)
                ->change();
        });
    }

    public function down(): void
    {
        // Make the column nullable again
        Schema::table('titles', function (Blueprint $table): void {
            $table->foreignId('title_type_id')
                ->nullable()
                ->change();
        });
    }
};
