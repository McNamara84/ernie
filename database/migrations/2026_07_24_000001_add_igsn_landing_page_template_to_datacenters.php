<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('datacenters', 'igsn_landing_page_template_id')) {
            Schema::table('datacenters', function (Blueprint $table): void {
                $table->foreignId('igsn_landing_page_template_id')
                    ->nullable()
                    ->after('landing_page_template_id')
                    ->constrained('landing_page_templates')
                    ->restrictOnDelete();
            });
        }

        $defaultIgsnTemplateId = DB::table('landing_page_templates')
            ->where('slug', LandingPageTemplate::IGSN_DEFAULT_TEMPLATE_SLUG)
            ->value('id');

        if ($defaultIgsnTemplateId !== null) {
            DB::table('datacenters')
                ->where('name', Datacenter::GFZ_NAME)
                ->update(['igsn_landing_page_template_id' => $defaultIgsnTemplateId]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('datacenters', 'igsn_landing_page_template_id')) {
            return;
        }

        Schema::table('datacenters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('igsn_landing_page_template_id');
        });
    }
};
