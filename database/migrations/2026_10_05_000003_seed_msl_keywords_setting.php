<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('thesaurus_settings')->insertOrIgnore([
            'type' => 'msl_keywords',
            'display_name' => 'EPOS MSL Keywords',
            'is_active' => true,
            'is_elmo_active' => true,
            'is_elmo_msl_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('thesaurus_settings')->where('type', 'msl_keywords')->delete();
    }
};
