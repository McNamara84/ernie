<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ThesaurusSetting;
use Illuminate\Database\Seeder;

class ThesaurusSettingSeeder extends Seeder
{
    public function run(): void
    {
        $thesauri = [
            [
                'type' => ThesaurusSetting::TYPE_SCIENCE_KEYWORDS,
                'display_name' => 'GCMD Science Keywords',
            ],
            [
                'type' => ThesaurusSetting::TYPE_PLATFORMS,
                'display_name' => 'GCMD Platforms',
            ],
            [
                'type' => ThesaurusSetting::TYPE_INSTRUMENTS,
                'display_name' => 'GCMD Instruments',
            ],
        ];

        foreach ($thesauri as $thesaurus) {
            ThesaurusSetting::firstOrCreate(
                ['type' => $thesaurus['type']],
                [
                    'display_name' => $thesaurus['display_name'],
                    'is_active' => true,
                    'is_elmo_active' => true,
                ]
            );
        }
    }
}
