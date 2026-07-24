<?php

declare(strict_types=1);

use App\Models\Datacenter;
use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const GFZ_DATACENTER_NAME = 'GFZ German Research Centre for Geosciences';

    public function up(): void
    {
        if (! Schema::hasColumn('datacenters', 'landing_page_template_id')) {
            Schema::table('datacenters', function (Blueprint $table): void {
                $table->foreignId('landing_page_template_id')
                    ->nullable()
                    ->after('name')
                    ->constrained('landing_page_templates')
                    ->restrictOnDelete();
            });
        }

        $defaultTemplate = LandingPageTemplate::ensureDefaultTemplateExists();
        $gfzDatacenter = Datacenter::query()
            ->where('name', self::GFZ_DATACENTER_NAME)
            ->first();

        if ($gfzDatacenter !== null && $gfzDatacenter->landing_page_template_id !== $defaultTemplate->id) {
            $gfzDatacenter->forceFill([
                'landing_page_template_id' => $defaultTemplate->id,
            ])->save();
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('datacenters', 'landing_page_template_id')) {
            return;
        }

        Schema::table('datacenters', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('landing_page_template_id');
        });
    }
};
