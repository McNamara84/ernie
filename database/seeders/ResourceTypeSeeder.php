<?php

namespace Database\Seeders;

use App\Models\ResourceType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ResourceTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            'Audiovisual',
            'Award',
            'Book',
            'Book Chapter',
            'Collection',
            'Computational Notebook',
            'Conference Paper',
            'Conference Proceeding',
            'Data Paper',
            'Dataset',
            'Dissertation',
            'Event',
            'Image',
            'Interactive Resource',
            'Instrument',
            'Journal',
            'Journal Article',
            'Model',
            'Output Management Plan',
            'Peer Review',
            'Physical Object',
            'Preprint',
            'Project',
            'Report',
            'Service',
            'Software',
            'Sound',
            'Standard',
            'Study Registration',
            'Text',
            'Workflow',
            'Other',
        ];

        foreach ($types as $name) {
            ResourceType::firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name]
            );
        }
    }
}
