<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('thesaurus_settings')->insert([
            'type' => 'euroscivoc',
            'display_name' => 'European Science Vocabulary (EuroSciVoc)',
            'is_active' => true,
            'is_elmo_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('thesaurus_settings')->where('type', 'euroscivoc')->delete();
    }
};
