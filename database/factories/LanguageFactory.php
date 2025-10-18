<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $languages = [
            ['code' => 'en', 'name' => 'English'],
            ['code' => 'de', 'name' => 'German'],
            ['code' => 'fr', 'name' => 'French'],
            ['code' => 'es', 'name' => 'Spanish'],
            ['code' => 'it', 'name' => 'Italian'],
            ['code' => 'pt', 'name' => 'Portuguese'],
            ['code' => 'nl', 'name' => 'Dutch'],
            ['code' => 'pl', 'name' => 'Polish'],
            ['code' => 'ru', 'name' => 'Russian'],
            ['code' => 'zh', 'name' => 'Chinese'],
        ];

        static $index = 0;
        $language = $languages[$index % count($languages)];
        $index++;

        return [
            'code' => $language['code'],
            'name' => $language['name'],
            'active' => true,
            'elmo_active' => true,
        ];
    }
}
