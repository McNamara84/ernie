<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessment_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 20)->index();
            $table->string('status', 30)->index();
            $table->string('active_scope', 20)->nullable()->unique();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_controlled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fuji_base_url', 500);
            $table->string('metric_version', 100)->nullable();
            $table->boolean('use_datacite');
            $table->boolean('use_github');
            $table->unsignedTinyInteger('concurrency')->default(2);
            $table->unsignedSmallInteger('requests_per_minute')->default(80);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('assessed')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('pending')->default(0);
            $table->text('pause_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['scope', 'created_at'], 'assessment_runs_scope_created_index');
        });

        Schema::create('assessment_run_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('assessment_runs')->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->string('identifier', 255)->nullable();
            $table->string('status', 30)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'resource_id']);
            $table->index(['run_id', 'status', 'available_at'], 'assessment_items_run_status_available_index');
            $table->index(['resource_id', 'status'], 'assessment_items_resource_status_index');
            $table->index('lease_expires_at', 'assessment_items_lease_expires_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_run_items');
        Schema::dropIfExists('assessment_runs');
    }
};
