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
        Schema::create('resource_related_identifiers', function (Blueprint $table) {
            $table->id();

            // Foreign Key to resources
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Identifier details
            $table->string('identifier', 2183); // Max length from metaworks analysis
            $table->string('identifier_type', 50); // DOI, URL, Handle, etc.
            $table->string('relation_type', 50); // Cites, References, etc.

            // Position for ordering (by add order)
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // Indexes for performance
            $table->index(['resource_id', 'position']);
            $table->index('relation_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_related_identifiers');
    }
};
