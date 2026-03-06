<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PidSetting;
use Illuminate\Database\Seeder;

class PidSettingSeeder extends Seeder
{
    public function run(): void
    {
        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_PID4INST],
            [
                'display_name' => 'PID4INST (b2inst)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );

        PidSetting::firstOrCreate(
            ['type' => PidSetting::TYPE_ROR],
            [
                'display_name' => 'ROR (Research Organization Registry)',
                'is_active' => true,
                'is_elmo_active' => true,
            ]
        );
    }
}
