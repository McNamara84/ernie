<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('pid_settings')->insertOrIgnore([
            'type' => 'raid',
            'display_name' => 'RAiD (Research Activity Identifier)',
            'is_active' => true,
            'is_elmo_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('pid_settings')
            ->where('type', 'raid')
            ->delete();
    }
};
