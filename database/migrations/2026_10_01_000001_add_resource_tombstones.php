<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->boolean('is_tombstone')->default(false)->index();
            $table->string('tombstone_reason', 40)->nullable();
            $table->text('tombstone_statement')->nullable();
            $table->timestamp('tombstoned_at')->nullable();
            $table->foreignId('tombstoned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('tombstone_revision')->default(0);
        });

        Schema::create('resource_tombstone_transitions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('revision');
            $table->string('action', 30);
            $table->string('reason', 40)->nullable();
            $table->text('statement')->nullable();
            $table->json('snapshot')->nullable();
            $table->string('doi');
            $table->boolean('test_mode');
            $table->string('previous_state', 20)->nullable();
            $table->text('previous_url')->nullable();
            $table->string('target_state', 20);
            $table->text('target_url');
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['resource_id', 'revision']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_tombstone_transitions');
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('tombstoned_by_user_id');
            $table->dropIndex(['is_tombstone']);
            $table->dropColumn(['is_tombstone', 'tombstone_reason', 'tombstone_statement', 'tombstoned_at', 'tombstone_revision']);
        });
    }
};
