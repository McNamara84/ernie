<?php

declare(strict_types=1);

use App\Models\LandingPageTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('landing_page_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('landing_page_templates', 'creator_display_limit')) {
                $table->unsignedSmallInteger('creator_display_limit')
                    ->default(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
                    ->after('left_column_order');
            }

            if (! Schema::hasColumn('landing_page_templates', 'contributor_display_limit')) {
                $table->unsignedSmallInteger('contributor_display_limit')
                    ->default(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
                    ->after('creator_display_limit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_page_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('landing_page_templates', 'contributor_display_limit')) {
                $table->dropColumn('contributor_display_limit');
            }

            if (Schema::hasColumn('landing_page_templates', 'creator_display_limit')) {
                $table->dropColumn('creator_display_limit');
            }
        });
    }
};
