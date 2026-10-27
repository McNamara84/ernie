<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_award_uri_reviews', function (Blueprint $table): void {
            $table->id();
            $table->string('decision', 16);
            $table->unsignedBigInteger('suggestion_id_at_review')->unique('award_uri_review_suggestion_unique');
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('resource_id_at_review');
            $table->unsignedBigInteger('funding_reference_id_at_review');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('reviewer_snapshot');
            $table->json('resource_snapshot');
            $table->json('funding_snapshot');
            $table->char('funding_identity_hash', 64);
            $table->char('review_fingerprint', 64);
            $table->char('proposed_uri_hash', 64);
            $table->string('old_award_uri', 512)->nullable();
            $table->string('new_award_uri', 512)->nullable();
            $table->string('proposed_award_uri', 512);
            $table->json('evidence');
            $table->json('confidence');
            $table->json('ambiguity');
            $table->string('rules_version', 32);
            $table->string('reason', 255)->nullable();
            $table->timestamp('reviewed_at');
            $table->index(['resource_id_at_review', 'funding_identity_hash', 'proposed_uri_hash', 'decision'], 'award_uri_review_identity_index');
        });
        Schema::create('funding_award_uri_discovery_states', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_resource_id')->default(0);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_award_uri_discovery_states');
        Schema::dropIfExists('funding_award_uri_reviews');
    }
};
