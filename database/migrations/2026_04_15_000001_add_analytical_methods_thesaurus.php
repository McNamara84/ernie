<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds a 'version' column to thesaurus_settings for vocabularies
     * that support multiple API versions (e.g., ARDC vocabularies).
     * Seeds the analytical_methods thesaurus setting.
     */
    public function up(): void
    {
        Schema::table('thesaurus_settings', function (Blueprint $table): void {
            $table->string('version')->nullable()->after('is_elmo_active');
        });

        $exists = DB::table('thesaurus_settings')
            ->where('type', 'analytical_methods')
            ->exists();

        if (! $exists) {
            DB::table('thesaurus_settings')->insert([
                'type' => 'analytical_methods',
                'display_name' => 'Analytical Methods for Geochemistry',
                'is_active' => true,
                'is_elmo_active' => true,
                'version' => '1-4',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('thesaurus_settings')
            ->where('type', 'analytical_methods')
            ->delete();

        Schema::table('thesaurus_settings', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
