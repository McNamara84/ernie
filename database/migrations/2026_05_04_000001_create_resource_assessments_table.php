<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_assessments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->unique()->constrained('resources')->cascadeOnDelete();
            $table->string('status', 32);
            $table->decimal('total_score', 6, 2)->nullable();
            $table->string('assessed_identifier', 255)->nullable();
            $table->text('error_message')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'total_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_assessments');
    }
};