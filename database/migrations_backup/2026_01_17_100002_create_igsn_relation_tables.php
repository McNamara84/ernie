<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create IGSN-specific relation tables for multi-value fields.
 *
 * These tables store IGSN-specific metadata that can have multiple values:
 * - Classifications (e.g., "Igneous", "Metamorphic")
 * - Geological ages (e.g., "Quaternary", "Archean")
 * - Geological units (e.g., "Permian", "Quaternary")
 *
 * Note: sample_other_names are stored as AlternativeTitle in the existing titles table.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Classifications (e.g., "Igneous", "Metamorphic", "Sedimentary")
        Schema::create('igsn_classifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });

        // Geological ages (e.g., "Quaternary", "Archean", "Jurassic")
        Schema::create('igsn_geological_ages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });

        // Geological units (e.g., "Permian", "Triassic")
        Schema::create('igsn_geological_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete();
            $table->string('value', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('resource_id');
        });
    }
};
