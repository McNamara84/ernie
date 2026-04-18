<?php

declare(strict_types=1);

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
        if (Schema::hasColumn('landing_pages', 'landing_page_template_id')) {
            return;
        }

        Schema::table('landing_pages', function (Blueprint $table) {
            $table->foreignId('landing_page_template_id')
                ->nullable()
                ->after('template')
                ->constrained('landing_page_templates')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('landing_pages', 'landing_page_template_id')) {
            return;
        }

        Schema::table('landing_pages', function (Blueprint $table) {
            $table->dropForeign(['landing_page_template_id']);
            $table->dropColumn('landing_page_template_id');
        });
    }
};
