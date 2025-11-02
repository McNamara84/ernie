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
        Schema::create('resource_funding_references', function (Blueprint $table) {
            $table->id();

            // Foreign Key to resources
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();

            // Funder details
            $table->string('funder_name');
            $table->string('funder_identifier')->nullable(); // ROR URL

            // Award/Grant details (all optional)
            $table->string('award_number')->nullable();
            $table->string('award_uri')->nullable();
            $table->text('award_title')->nullable();

            // Position for ordering (drag & drop)
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            // Indexes for performance
            $table->index(['resource_id', 'position']);
            $table->index('funder_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resource_funding_references');
    }
};
