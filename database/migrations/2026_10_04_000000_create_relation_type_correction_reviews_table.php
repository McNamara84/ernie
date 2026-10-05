<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('relation_type_correction_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('related_identifier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('suggestion_id')->unique();
            $table->string('decision', 16);
            $table->string('reason', 255)->nullable();
            $table->char('context_fingerprint', 64);
            $table->char('review_fingerprint', 64);
            // Durable snapshots deliberately have no cascading type/user/resource FK.
            $table->json('snapshot');
            $table->timestamp('reviewed_at');
            $table->index(['resource_id', 'reviewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('relation_type_correction_reviews');
    }
};
