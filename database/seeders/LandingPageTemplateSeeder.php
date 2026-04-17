<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LandingPageTemplate;
use Illuminate\Database\Seeder;

class LandingPageTemplateSeeder extends Seeder
{
    /**
     * Seed the default landing page template.
     *
     * This creates the immutable default GFZ Data Services template
     * that serves as the base for cloning custom templates.
     */
    public function run(): void
    {
        LandingPageTemplate::firstOrCreate(
            ['slug' => 'default_gfz'],
            [
                'name' => 'Default GFZ Data Services',
                'is_default' => true,
                'logo_path' => null,
                'logo_filename' => null,
                'right_column_order' => LandingPageTemplate::RIGHT_COLUMN_SECTIONS,
                'left_column_order' => LandingPageTemplate::LEFT_COLUMN_SECTIONS,
                'created_by' => null,
            ]
        );
    }
}
