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
        Schema::create('suggested_size_format', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('doi');
            $table->string('suggested_filetype', 3); // "abc"
            $table->string('source_url')->nullable();
            $table->string('probe_method', 30)->nullable();
            $table->string('confidence', 10)->nullable();
            $table->boolean('is_zip')->default(false);
            $table->boolean('discovered_in_fileName')->default(false);
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['resource_id', 'doi'], 'suggested_size_format_unique');
            $table->index(['discovered_at', 'id'], 'suggested_size_format_discovered_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_size_format');
    }
};