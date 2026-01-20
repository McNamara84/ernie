<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DateType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DateType>
 */
class DateTypeFactory extends Factory
{
    protected $model = DateType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $types = [
            ['name' => 'Created', 'slug' => 'created'],
            ['name' => 'Collected', 'slug' => 'collected'],
            ['name' => 'Issued', 'slug' => 'issued'],
            ['name' => 'Updated', 'slug' => 'updated'],
            ['name' => 'Valid', 'slug' => 'valid'],
            ['name' => 'Available', 'slug' => 'available'],
            ['name' => 'Accepted', 'slug' => 'accepted'],
            ['name' => 'Submitted', 'slug' => 'submitted'],
            ['name' => 'Copyrighted', 'slug' => 'copyrighted'],
            ['name' => 'Withdrawn', 'slug' => 'withdrawn'],
            ['name' => 'Other', 'slug' => 'other'],
        ];

        static $index = 0;
        $type = $types[$index % count($types)];
        $index++;

        return [
            'name' => $type['name'],
            'slug' => $type['slug'],
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the date type is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
