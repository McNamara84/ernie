<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Creates the alternate_identifiers table for storing DataCite alternateIdentifier elements.
     * Used primarily for IGSN resources to store:
     * - 'name' field with type "Local accession number"
     * - 'sample_other_names' field with type "Local sample name"
     *
     * @see https://github.com/McNamara84/ernie/issues/465
     */
    public function up(): void
    {
        Schema::create('alternate_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('type'); // e.g., "Local accession number", "Local sample name"
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['resource_id', 'position']);
        });
    }
};
