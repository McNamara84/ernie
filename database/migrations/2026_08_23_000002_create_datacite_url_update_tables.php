<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('datacite_url_update_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 20)->index();
            $table->string('status', 30)->index();
            $table->string('active_marker', 20)->nullable()->unique();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_controlled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('test_mode');
            $table->string('datacite_endpoint', 500);
            $table->string('target_base_url', 500);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('already_current')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->text('pause_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'created_at']);
        });

        Schema::create('datacite_url_update_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('datacite_url_update_runs')->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->string('identifier', 255);
            $table->string('status', 50)->index();
            $table->string('before_url', 2048)->nullable();
            $table->string('target_url', 2048);
            $table->string('datacite_state', 30)->nullable();
            $table->unsignedSmallInteger('preflight_attempts')->default(0);
            $table->unsignedSmallInteger('update_attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'resource_id']);
            $table->index(['run_id', 'status']);
            $table->index(['identifier', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('datacite_url_update_items');
        Schema::dropIfExists('datacite_url_update_runs');
    }
};
