<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_curation_states', function (Blueprint $table): void {
            $table->foreignId('resource_id')->primary()->constrained('resources')->cascadeOnDelete();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('start_source', 30);
            $table->timestamp('started_at');
            $table->unsignedInteger('checklist_version')->default(1);
            $table->foreignId('lead_curator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('waiting_for', 20)->nullable();
            $table->text('waiting_text')->nullable();
            $table->timestamp('waiting_since')->nullable();
            $table->text('needs_help_text')->nullable();
            $table->date('deadline')->nullable();
            $table->text('deadline_reason')->nullable();
            $table->timestamp('last_activity_at');
            $table->unsignedBigInteger('revision')->default(0);
        });
        Schema::create('resource_curators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at');
            $table->unique(['resource_id', 'user_id']);
            $table->index(['user_id', 'resource_id']);
        });
        Schema::create('resource_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('item_key', 80);
            $table->string('state', 20);
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name')->nullable();
            $table->timestamp('changed_at');
            $table->unsignedBigInteger('revision')->default(0);
            $table->unique(['resource_id', 'item_key']);
        });
        Schema::create('resource_curation_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('type', 60);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->json('payload');
            $table->text('body')->nullable();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamp('created_at');
            $table->timestamp('edited_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->index(['resource_id', 'created_at', 'id'], 'curation_event_cursor');
            $table->index(['resource_id', 'type', 'id'], 'curation_event_type');
        });
        Schema::create('resource_review_link_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->uuid('operation_id');
            $table->string('kind', 20);
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('queued_at');
            $table->unsignedInteger('queued_recipients');
            $table->json('queued_recipient_keys')->nullable();
            $table->unsignedInteger('skipped_recipients')->default(0);
            $table->unique(['resource_id', 'operation_id'], 'curation_dispatch_operation');
        });
        Schema::create('resource_doi_registration_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('doi');
            $table->string('environment', 20);
            $table->string('state', 20);
            $table->string('source', 30);
            $table->timestamp('confirmed_at');
            $table->unique(['resource_id', 'doi', 'environment'], 'curation_registration_identity');
        });
        Schema::create('resource_data_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('type', 30);
            $table->text('location');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('resource_curation_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('resource_curation_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['event_id', 'user_id']);
        });
        Schema::create('resource_curation_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('resource_curation_events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->timestamp('queued_at')->nullable();
            $table->unique(['event_id', 'user_id', 'type'], 'curation_notification_unique');
        });
        Schema::table('resource_listing_projections', function (Blueprint $table): void {
            $table->boolean('curation_started')->default(false);
            $table->unsignedTinyInteger('curation_progress')->default(0);
            $table->unsignedTinyInteger('curation_manual_open')->default(8);
            $table->boolean('curation_open')->default(false);
            $table->timestamp('curation_activity_at')->nullable();
            $table->timestamp('curation_published_at')->nullable();
            $table->timestamp('curation_waiting_since')->nullable();
            $table->boolean('curation_needs_help')->default(false);
            $table->date('curation_deadline')->nullable();
            $table->text('curation_contact')->nullable();
            $table->index(['curation_started', 'workflow_status', 'curation_activity_at', 'resource_id'], 'curation_activity_sort');
            $table->index(['curation_started', 'curation_deadline', 'resource_id'], 'curation_deadline_sort');
            $table->index(['curation_started', 'curation_progress', 'resource_id'], 'curation_progress_sort');
        });
    }

    public function down(): void
    {
        Schema::table('resource_listing_projections', function (Blueprint $table): void {
            $table->dropIndex('curation_activity_sort');
            $table->dropIndex('curation_deadline_sort');
            $table->dropIndex('curation_progress_sort');
            $table->dropColumn(['curation_started', 'curation_progress', 'curation_manual_open', 'curation_open', 'curation_activity_at', 'curation_published_at', 'curation_waiting_since', 'curation_needs_help', 'curation_deadline', 'curation_contact']);
        });
        foreach (['resource_curation_notifications', 'resource_curation_mentions', 'resource_data_locations', 'resource_doi_registration_evidence', 'resource_review_link_dispatches', 'resource_curation_events', 'resource_checklist_items', 'resource_curators', 'resource_curation_states'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
