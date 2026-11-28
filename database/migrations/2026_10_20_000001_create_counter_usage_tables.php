<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_list_versions', function (Blueprint $table): void {
            $table->id();
            $table->char('checksum', 64)->unique();
            $table->json('rules');
            $table->json('sources');
            $table->timestamp('created_at');
        });

        Schema::create('usage_tracking_state', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->timestamp('started_at', 6)->nullable();
            $table->boolean('paused')->default(false);
            $table->timestamp('paused_at', 6)->nullable();
            $table->foreignId('list_version_id')->nullable()->constrained('usage_list_versions');
            $table->unsignedBigInteger('metrics_revision')->default(0);
            $table->timestamp('last_aggregated_at')->nullable();
            $table->date('data_through')->nullable();
        });
        DB::table('usage_tracking_state')->insert(['id' => 1]);

        Schema::create('usage_aggregation_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->timestamp('finalized_at')->nullable();
            $table->boolean('incomplete')->default(false);
        });

        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 16);
            $table->string('access_method', 8);
            $table->char('session_hash', 64);
            $table->char('target_hash', 64);
            $table->date('day')->index();
            $table->unsignedBigInteger('occurred_at_ms');
            $table->foreignId('list_version_id')->constrained('usage_list_versions');
            $table->index(['day', 'landing_page_id', 'access_method'], 'usage_events_aggregate');
        });

        Schema::create('usage_search_events', function (Blueprint $table): void {
            $table->id();
            $table->string('portal_scope', 8);
            $table->text('normalized_term');
            $table->char('term_hash', 64);
            $table->string('access_method', 8);
            $table->char('session_hash', 64);
            $table->date('day')->index();
            $table->unsignedBigInteger('occurred_at_ms');
            $table->foreignId('list_version_id')->constrained('usage_list_versions');
        });

        foreach (['usage_daily_metrics' => 'day', 'usage_monthly_metrics' => 'month'] as $name => $period) {
            Schema::create($name, function (Blueprint $table) use ($period): void {
                $table->id();
                $table->foreignId('landing_page_id')->constrained()->cascadeOnDelete();
                $table->date($period)->index();
                $table->string('access_method', 8);
                $table->unsignedBigInteger('total_investigations');
                $table->unsignedBigInteger('unique_investigations');
                $table->unsignedBigInteger('total_requests')->nullable();
                $table->unsignedBigInteger('unique_requests')->nullable();
                $table->unique(['landing_page_id', $period, 'access_method'], "usage_{$period}_unique");
            });
        }

        Schema::create('usage_search_daily_metrics', function (Blueprint $table): void {
            $table->id();
            $table->date('day')->index();
            $table->string('portal_scope', 8);
            $table->text('normalized_term');
            $table->char('term_hash', 64);
            $table->string('access_method', 8);
            $table->unsignedBigInteger('search_count');
            $table->unique(['day', 'portal_scope', 'term_hash', 'access_method'], 'usage_search_day_unique');
        });
    }

    public function down(): void
    {
        foreach (['usage_search_daily_metrics', 'usage_monthly_metrics', 'usage_daily_metrics', 'usage_search_events', 'usage_events', 'usage_aggregation_days', 'usage_tracking_state', 'usage_list_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
