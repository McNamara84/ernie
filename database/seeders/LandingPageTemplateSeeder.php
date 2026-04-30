<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\LandingPageTemplate;
use Illuminate\Database\Seeder;

class LandingPageTemplateSeeder extends Seeder
{
    /**
     * Seed the immutable system landing page templates.
     *
     * Creates the two default templates that serve as the base for cloning
     * custom templates: the GFZ Data Services default for resources (DOIs)
     * and the GFZ IGSN default for physical samples.
     */
    public function run(): void
    {
        LandingPageTemplate::ensureSystemTemplatesExist();
    }
}
