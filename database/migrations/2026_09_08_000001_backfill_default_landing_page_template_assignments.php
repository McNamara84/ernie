<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $resourceTemplateId = DB::table('landing_page_templates')
            ->where('slug', LandingPageTemplate::DEFAULT_TEMPLATE_SLUG)
            ->value('id');
        $igsnTemplateId = DB::table('landing_page_templates')
            ->where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)
            ->value('id');

        if ($resourceTemplateId === null || $igsnTemplateId === null) {
            throw new RuntimeException('Both built-in landing-page templates must exist before datacenter assignments are backfilled.');
        }

        DB::table('datacenters')
            ->whereNull('landing_page_template_id')
            ->update(['landing_page_template_id' => $resourceTemplateId]);

        DB::table('datacenters')
            ->whereNull('igsn_landing_page_template_id')
            ->update(['igsn_landing_page_template_id' => $igsnTemplateId]);
    }

    public function down(): void
    {
        // Intentionally empty: default assignments cannot be distinguished from
        // assignments that users explicitly moved back to a built-in template.
    }
};
