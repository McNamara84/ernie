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
        Schema::create('suggested_relations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('identifier', 500);
            $table->foreignId('identifier_type_id')->constrained('identifier_types')->restrictOnDelete();
            $table->foreignId('relation_type_id')->constrained('relation_types')->restrictOnDelete();
            $table->string('source');
            $table->string('source_title')->nullable();
            $table->string('source_type')->nullable();
            $table->string('source_publisher')->nullable();
            $table->string('source_publication_date')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['resource_id', 'identifier', 'relation_type_id'], 'suggested_relations_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_relations');
    }
};
