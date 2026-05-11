<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guided_tours', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150);
            $table->unsignedInteger('version');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('start_route', 120);
            $table->json('target_roles');
            $table->boolean('is_active')->default(true);
            $table->boolean('auto_assign')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['is_active', 'auto_assign']);
            $table->index(['start_route', 'is_active']);
        });

        Schema::create('user_guided_tour_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('guided_tour_id')->constrained('guided_tours')->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->string('assignment_source', 32)->default('automatic');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'guided_tour_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_guided_tour_assignments');
        Schema::dropIfExists('guided_tours');
    }
};