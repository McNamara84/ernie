<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create the generic assistant_suggestions and assistant_dismissed tables.
 *
 * These tables are used by new student-created assistant modules that extend
 * GenericTableAssistant. Existing assistants (ORCID, ROR, Relations) keep
 * their own dedicated tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('assistant_id', 100);
            $table->foreignId('resource_id')->nullable()->constrained('resources')->cascadeOnDelete();
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->string('suggested_value', 1000);
            $table->string('suggested_label', 1000);
            $table->decimal('similarity_score', 5, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('discovered_at');
            $table->timestamps();

            // Prevent duplicate suggestions per assistant + target + value
            $table->unique(['assistant_id', 'target_type', 'target_id', 'suggested_value'], 'assistant_suggestions_unique');

            // Fast lookup per assistant for pagination
            $table->index(['assistant_id', 'discovered_at']);
        });

        Schema::create('assistant_dismissed', function (Blueprint $table) {
            $table->id();
            $table->string('assistant_id', 100);
            $table->string('target_type', 100);
            $table->unsignedBigInteger('target_id');
            $table->string('dismissed_value', 1000);
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();

            // Prevent duplicate dismissed entries
            $table->unique(['assistant_id', 'target_type', 'target_id', 'dismissed_value'], 'assistant_dismissed_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_dismissed');
        Schema::dropIfExists('assistant_suggestions');
    }
};
