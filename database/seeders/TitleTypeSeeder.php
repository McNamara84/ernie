<?php

namespace Database\Seeders;

use App\Models\TitleType;
use Illuminate\Database\Seeder;

class TitleTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            ['name' => 'Main Title', 'slug' => 'main-title'],
            ['name' => 'Alternative Title', 'slug' => 'alternative-title'],
            ['name' => 'Subtitle', 'slug' => 'subtitle'],
            ['name' => 'TranslatedTitle', 'slug' => 'translated-title'],
            ['name' => 'Other', 'slug' => 'other'],
        ];

        foreach ($types as $type) {
            TitleType::firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']]
            );
        }
    }
}
