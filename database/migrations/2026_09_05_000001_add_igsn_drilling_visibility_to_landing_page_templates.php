<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_page_templates', function (Blueprint $table): void {
            if (! Schema::hasColumn('landing_page_templates', 'show_igsn_drilling')) {
                $table->boolean('show_igsn_drilling')
                    ->default(true)
                    ->after('citation_author_display_limit');
            }
        });
    }

    public function down(): void
    {
        Schema::table('landing_page_templates', function (Blueprint $table): void {
            if (Schema::hasColumn('landing_page_templates', 'show_igsn_drilling')) {
                $table->dropColumn('show_igsn_drilling');
            }
        });
    }
};
