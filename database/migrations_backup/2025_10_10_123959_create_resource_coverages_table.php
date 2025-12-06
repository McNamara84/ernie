<?php

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
        Schema::create('resource_coverages', function (Blueprint $table) {
            $table->id();

            // Foreign Key to resources
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Spatial Coverage (Coordinates)
            $table->decimal('lat_min', 10, 6)->nullable();
            $table->decimal('lat_max', 10, 6)->nullable();
            $table->decimal('lon_min', 10, 6)->nullable();
            $table->decimal('lon_max', 10, 6)->nullable();

            // Temporal Coverage (Date/Time)
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->string('timezone', 100)->default('UTC');

            // Description
            $table->text('description')->nullable();

            $table->timestamps();

            // Index for performance
            $table->index('resource_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_coverages');
    }
};
