<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_metric_samples', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('recorded_at')->unique();
            $table->decimal('cpu_usage_percent', 5, 2)->nullable();
            $table->decimal('memory_usage_percent', 5, 2);
            $table->unsignedBigInteger('memory_used_bytes');
            $table->unsignedBigInteger('memory_total_bytes');
            $table->unsignedBigInteger('cpu_total_ticks');
            $table->unsignedBigInteger('cpu_idle_ticks');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_metric_samples');
    }
};
