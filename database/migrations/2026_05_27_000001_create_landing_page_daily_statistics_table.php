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
        Schema::create('landing_page_daily_statistics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')
                ->constrained('landing_pages')
                ->cascadeOnDelete();
            $table->date('statistic_date');
            $table->unsignedInteger('page_view_count')->default(0);
            $table->unsignedInteger('file_download_click_count')->default(0);
            $table->timestamps();

            $table->unique(['landing_page_id', 'statistic_date'], 'lp_daily_stats_page_date_unique');
            $table->index('statistic_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_daily_statistics');
    }
};
