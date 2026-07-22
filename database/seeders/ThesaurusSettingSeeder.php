<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ThesaurusSetting;
use Illuminate\Database\Seeder;

class ThesaurusSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ThesaurusSetting::definitions() as $type => $displayName) {
            ThesaurusSetting::firstOrCreate(
                ['type' => $type],
                [
                    'display_name' => $displayName,
                    'is_active' => true,
                    'is_elmo_active' => true,
                ]
            );
        }
    }
}
