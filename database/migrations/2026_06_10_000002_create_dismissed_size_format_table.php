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
        Schema::create('dismissed_size_formats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('doi')->constrained('dois')->cascadeOnDelete();
            $table->string('suggested_filetype', 3); // "abc"
            $table->boolean('is_zip')->default(false);
            $table->boolean('discovered_in_fileName')->default(false);
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['resource_id', 'doi'], 'dismissed_size_formats_unique');
            $table->index(['dismissed_at', 'id'], 'dismissed_size_formats_dismissed_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dismissed_size_formats');
    }
};
