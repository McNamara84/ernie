<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('public_traffic_hourly_statistics', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('bucket_started_at')->unique();
            $table->unsignedInteger('landing_page_unique_visitor_count')->default(0);
            $table->unsignedInteger('portal_unique_visitor_count')->default(0);
            $table->unsignedInteger('combined_unique_visitor_count')->default(0);
            $table->unsignedTinyInteger('observed_minute_count')->default(0);
            $table->timestamp('last_observed_minute_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_traffic_hourly_statistics');
    }
};
