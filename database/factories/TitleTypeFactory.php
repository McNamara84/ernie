<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TitleType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TitleType>
 */
class TitleTypeFactory extends Factory
{
    protected $model = TitleType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $types = [
            ['name' => 'Main Title', 'slug' => 'main-title'],
            ['name' => 'Subtitle', 'slug' => 'subtitle'],
            ['name' => 'Alternative Title', 'slug' => 'alternative-title'],
            ['name' => 'Translated Title', 'slug' => 'translated-title'],
            ['name' => 'Other', 'slug' => 'other'],
        ];

        static $index = 0;
        $type = $types[$index % count($types)];
        $index++;

        return [
            'name' => $type['name'],
            'slug' => $type['slug'],
            'is_active' => true,
            'is_elmo_active' => true,
        ];
    }
}
