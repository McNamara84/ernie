<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->exists()) {
            DB::table('thesaurus_settings')->insert([
                'type' => 'msl_laboratories',
                'display_name' => 'MSL Laboratories',
                'is_active' => true,
                'is_elmo_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('thesaurus_settings')->where('type', 'msl_laboratories')->delete();
    }
};
