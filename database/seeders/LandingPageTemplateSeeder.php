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
        LandingPageTemplate::ensureDefaultTemplateExists();
    }
}
