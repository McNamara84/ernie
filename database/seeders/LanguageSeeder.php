<?php

namespace Database\Seeders;

use App\Models\Language;
use Illuminate\Database\Seeder;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        $languages = [
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'de', 'name' => 'German'],
            ['code' => 'fr', 'name' => 'French'],
        ];

        foreach ($languages as $lang) {
            Language::firstOrCreate(['code' => $lang['code']], ['name' => $lang['name']]);
        }
    }
}
