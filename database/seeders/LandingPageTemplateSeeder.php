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

        $gfz = Datacenter::query()->firstOrCreate([
            'name' => Datacenter::GFZ_NAME,
        ]);
        $initializeIgsnAssignment = $gfz->landing_page_template_id === null
            && $gfz->igsn_landing_page_template_id === null;

        $assignments = [
            'landing_page_template_id' => $templates['resource']->id,
        ];

        if ($initializeIgsnAssignment) {
            $assignments['igsn_landing_page_template_id'] = $templates['igsn']->id;
        }

        $gfz->forceFill($assignments)->save();
    }
}
