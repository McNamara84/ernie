<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Seeder;

class LandingPageTemplateSeeder extends Seeder
{
    /**
     * Seed the immutable system landing page copy templates.
     *
     * Creates the two templates that serve as the base for cloning custom
     * templates and as technical fallbacks for resources and physical samples.
     */
    public function run(): void
    {
        $templates = LandingPageTemplate::ensureSystemTemplatesExist();

        Datacenter::query()
            ->whereNull('landing_page_template_id')
            ->update(['landing_page_template_id' => $templates[LandingPageTemplate::TEMPLATE_TYPE_RESOURCE]->id]);

        Datacenter::query()
            ->whereNull('igsn_landing_page_template_id')
            ->update(['igsn_landing_page_template_id' => $templates[LandingPageTemplate::TEMPLATE_TYPE_IGSN]->id]);
    }
}
