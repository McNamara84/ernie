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
            if (! Schema::hasColumn('landing_page_templates', 'citation_author_display_limit')) {
                $table->unsignedSmallInteger('citation_author_display_limit')
                    ->default(LandingPageTemplate::DEFAULT_DISPLAY_LIMIT)
                    ->after('contributor_display_limit');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_page_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('landing_page_templates', 'citation_author_display_limit')) {
                $table->dropColumn('citation_author_display_limit');
            }
        });
    }
};
