<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('thesaurus_settings')->where('type', 'simple_lithology')->exists()) {
            DB::table('thesaurus_settings')->insert([
                'type' => 'simple_lithology',
                'display_name' => 'CGI Simple Lithology',
                'is_active' => false,
                'is_elmo_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('thesaurus_settings')->where('type', 'simple_lithology')->delete();
    }
};
