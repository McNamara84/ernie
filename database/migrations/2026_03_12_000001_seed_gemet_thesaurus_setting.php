<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Seed the GEMET thesaurus setting.
     * Uses query builder instead of Eloquent to avoid coupling to model changes.
     */
    public function up(): void
    {
        $exists = DB::table('thesaurus_settings')
            ->where('type', 'gemet')
            ->exists();

        if (! $exists) {
            DB::table('thesaurus_settings')->insert([
                'type' => 'gemet',
                'display_name' => 'GEMET Thesaurus',
                'is_active' => true,
                'is_elmo_active' => true,
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
            ->where('type', 'gemet')
            ->delete();
    }
};
