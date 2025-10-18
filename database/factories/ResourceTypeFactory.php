<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ResourceType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResourceType>
 */
class ResourceTypeFactory extends Factory
{
    protected $model = ResourceType::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $types = [
            ['name' => 'Dataset', 'slug' => 'dataset'],
            ['name' => 'Text', 'slug' => 'text'],
            ['name' => 'Image', 'slug' => 'image'],
            ['name' => 'Software', 'slug' => 'software'],
            ['name' => 'Collection', 'slug' => 'collection'],
            ['name' => 'Audiovisual', 'slug' => 'audiovisual'],
            ['name' => 'Model', 'slug' => 'model'],
            ['name' => 'Workflow', 'slug' => 'workflow'],
            ['name' => 'Service', 'slug' => 'service'],
            ['name' => 'Sound', 'slug' => 'sound'],
        ];

        static $index = 0;
        $type = $types[$index % count($types)];
        $index++;

        return [
            'name' => $type['name'],
            'slug' => $type['slug'],
            'active' => true,
            'elmo_active' => true,
        ];
    }
}
