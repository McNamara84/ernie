<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('igsn_registration_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('last_controlled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 30)->index();
            $table->boolean('test_mode');
            $table->string('datacite_endpoint', 500);
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('registered')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('cancelled')->default(0);
            $table->text('pause_reason')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['initiated_by_user_id', 'status', 'created_at'], 'igsn_registration_runs_user_status_created_index');
        });

        Schema::create('igsn_registration_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('run_id')->constrained('igsn_registration_runs')->cascadeOnDelete();
            $table->foreignId('resource_id')->nullable()->constrained('resources')->nullOnDelete();
            $table->string('identifier', 255);
            $table->string('status', 30)->index();
            $table->string('operation', 20)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['run_id', 'resource_id']);
            $table->index(['run_id', 'status']);
            $table->index(['resource_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('igsn_registration_items');
        Schema::dropIfExists('igsn_registration_runs');
    }
};
