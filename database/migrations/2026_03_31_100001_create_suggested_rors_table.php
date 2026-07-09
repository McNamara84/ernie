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
        Schema::create('suggested_rors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->string('entity_name', 512);
            $table->string('suggested_ror_id', 255);
            $table->string('suggested_name', 512);
            $table->decimal('similarity_score', 5, 4)->default(0);
            $table->json('ror_aliases')->nullable();
            $table->string('existing_identifier', 512)->nullable();
            $table->string('existing_identifier_type', 100)->nullable();
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['entity_type', 'entity_id', 'suggested_ror_id'], 'suggested_rors_entity_ror_unique');
            $table->index(['resource_id', 'entity_type']);
            $table->index(['discovered_at', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_rors');
    }
};
