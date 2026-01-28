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
            ['name' => 'Dataset', 'slug' => 'dataset', 'description' => 'Data encoded in a defined structure.'],
            ['name' => 'Text', 'slug' => 'text', 'description' => 'A resource consisting primarily of words for reading that is not covered by any other textual resource type in this list.'],
            ['name' => 'Image', 'slug' => 'image', 'description' => 'A visual representation other than text.'],
            ['name' => 'Software', 'slug' => 'software', 'description' => 'A computer program other than a computational notebook, in either source code (text) or compiled form. Use this type for general software components supporting scholarly research.'],
            ['name' => 'Collection', 'slug' => 'collection', 'description' => 'An aggregation of resources, which may encompass collections of one resourceType as well as those of mixed types. A collection is described as a group; its parts may also be separately described.'],
            ['name' => 'Audiovisual', 'slug' => 'audiovisual', 'description' => 'A series of visual representations imparting an impression of motion when shown in succession. May or may not include sound.'],
            ['name' => 'Model', 'slug' => 'model', 'description' => 'An abstract, conceptual, graphical, mathematical or visualization model that represents empirical objects, phenomena, or physical processes.'],
            ['name' => 'Workflow', 'slug' => 'workflow', 'description' => 'A structured series of steps which can be executed to produce a final outcome, allowing users a means to specify and enact their work in a more reproducible manner.'],
            ['name' => 'Service', 'slug' => 'service', 'description' => 'An organized system of apparatus, appliances, staff, etc., for supplying some function(s) required by end users.'],
            ['name' => 'Sound', 'slug' => 'sound', 'description' => 'A resource primarily intended to be heard.'],
        ];

        static $index = 0;
        $type = $types[$index % count($types)];
        $index++;

        return [
            'name' => $type['name'],
            'slug' => $type['slug'],
            'description' => $type['description'],
            'is_active' => true,
            'is_elmo_active' => true,
        ];
    }
}
