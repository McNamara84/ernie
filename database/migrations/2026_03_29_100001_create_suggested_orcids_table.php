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
        Schema::create('suggested_orcids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->string('suggested_orcid', 19); // "0000-0001-2345-6789"
            $table->decimal('similarity_score', 5, 4)->default(0);
            $table->string('candidate_first_name')->nullable();
            $table->string('candidate_last_name')->nullable();
            $table->json('candidate_affiliations')->nullable();
            $table->string('source_context', 20); // 'creator' or 'contributor'
            $table->timestamp('discovered_at');
            $table->timestamps();

            $table->unique(['resource_id', 'person_id', 'suggested_orcid'], 'suggested_orcids_unique');
            $table->index(['discovered_at', 'id'], 'suggested_orcids_discovered_at_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('suggested_orcids');
    }
};
