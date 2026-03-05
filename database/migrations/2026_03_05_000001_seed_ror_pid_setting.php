<?php

declare(strict_types=1);

use App\Models\PidSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('pid_settings')->insertOrIgnore([
            'type' => PidSetting::TYPE_ROR,
            'display_name' => 'ROR (Research Organization Registry)',
            'is_active' => true,
            'is_elmo_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
