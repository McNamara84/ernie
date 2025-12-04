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
        Schema::create('landing_pages', function (Blueprint $table) {
            $table->id();

            // Foreign Key to resources
            $table->foreignId('resource_id')
                ->unique()
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Template and configuration
            $table->string('template', 50)->default('default_gfz');
            $table->string('ftp_url', 2048)->nullable();

            // Status and preview
            $table->enum('status', ['draft', 'published'])->default('draft');
            $table->string('preview_token', 64)->nullable()->unique();
            $table->timestamp('published_at')->nullable();

            // Analytics
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();

            $table->timestamps();

            // Indexes for performance
            $table->index('status');
            $table->index('template');
            $table->index('preview_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_pages');
    }
};
