<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Datacenter;
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
        $templates = LandingPageTemplate::ensureSystemTemplatesExist();

        Datacenter::query()->firstOrCreate([
            'name' => 'GFZ German Research Centre for Geosciences',
        ])->forceFill([
            'landing_page_template_id' => $templates['resource']->id,
        ])->save();
    }
}
