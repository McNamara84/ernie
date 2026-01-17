<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create igsn_metadata table for IGSN-specific sample information.
 *
 * This table has a 1:1 relationship with resources (enforced by unique constraint).
 * It stores metadata specific to physical samples that don't fit the standard
 * DataCite schema but are required for IGSN registration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('igsn_metadata', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')
                ->unique()  // Enforces 1:1 relationship
                ->constrained('resources')
                ->cascadeOnDelete();

            // Sample properties
            $table->string('sample_type', 100)->nullable();
            $table->string('material', 255)->nullable();
            $table->boolean('is_private')->default(false);
            $table->decimal('size', 12, 4)->nullable();
            $table->string('size_unit', 100)->nullable();

            // Depth information
            $table->decimal('depth_min', 10, 2)->nullable();
            $table->decimal('depth_max', 10, 2)->nullable();
            $table->string('depth_scale', 100)->nullable();

            // Collection details
            $table->text('sample_purpose')->nullable();
            $table->string('collection_method', 255)->nullable();
            $table->text('collection_method_description')->nullable();
            $table->string('collection_date_precision', 20)->nullable();

            // Platform/expedition
            $table->string('cruise_field_program', 255)->nullable();
            $table->string('platform_type', 100)->nullable();
            $table->string('platform_name', 100)->nullable();
            $table->string('platform_description', 255)->nullable();

            // Archive & access
            $table->string('current_archive', 255)->nullable();
            $table->string('current_archive_contact', 255)->nullable();
            $table->string('sample_access', 50)->nullable();
            $table->string('operator', 255)->nullable();

            // Technical details
            $table->string('coordinate_system', 50)->nullable();
            $table->string('user_code', 50)->nullable();
            $table->json('description_json')->nullable();

            // Upload tracking
            $table->string('upload_status', 50)->default('pending');
            $table->text('upload_error_message')->nullable();
            $table->string('csv_filename', 255)->nullable();
            $table->unsignedInteger('csv_row_number')->nullable();

            $table->timestamps();

            $table->index('upload_status');
            $table->index('sample_type');
        });
    }
};
