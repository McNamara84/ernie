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
        Schema::create('portal_search_daily_statistics', function (Blueprint $table): void {
            $table->id();
            $table->date('statistic_date');
            $table->string('normalized_term', 255);
            $table->unsignedInteger('search_count')->default(0);
            $table->timestamps();

            $table->unique(['statistic_date', 'normalized_term']);
            $table->index('normalized_term');
            $table->index('statistic_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('portal_search_daily_statistics');
    }
};