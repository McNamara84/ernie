<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_controlled_keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')
                ->constrained('resources')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('keyword_id', 512); // Full URI from GCMD (e.g., "https://gcmd.earthdata.nasa.gov/kms/concept/...")
            $table->string('text', 255); // Last element of the path
            $table->text('path'); // Full hierarchical path (e.g., "EARTH SCIENCE > AGRICULTURE > SOILS")
            $table->string('language', 10)->default('en');
            $table->string('scheme', 255); // e.g., "Earth Science"
            $table->string('scheme_uri', 512); // e.g., "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords"
            $table->enum('vocabulary_type', ['science', 'platforms', 'instruments']);
            $table->timestamps();

            $table->index(['resource_id']);
            $table->index(['vocabulary_type']);
            $table->index(['keyword_id'], 'idx_keyword_id'); // Named index due to key length
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_controlled_keywords');
    }
};
