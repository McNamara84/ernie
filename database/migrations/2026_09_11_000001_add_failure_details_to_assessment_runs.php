<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_runs', function (Blueprint $table): void {
            $table->unsignedInteger('service_errors')->default(0)->after('failed');
        });

        Schema::table('assessment_run_items', function (Blueprint $table): void {
            $table->string('failure_type', 32)->nullable()->after('last_http_status')->index();
            $table->string('error_code', 64)->nullable()->after('failure_type');
            $table->text('error_detail')->nullable()->after('error_message');
            $table->unsignedInteger('last_attempt_duration_ms')->nullable()->after('error_detail');
        });

        Schema::table('resource_assessments', function (Blueprint $table): void {
            $table->string('failure_type', 32)->nullable()->after('status');
            $table->string('error_code', 64)->nullable()->after('failure_type');
            $table->index(['status', 'failure_type'], 'resource_assessments_status_failure_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('resource_assessments', function (Blueprint $table): void {
            $table->dropIndex('resource_assessments_status_failure_type_index');
            $table->dropColumn(['failure_type', 'error_code']);
        });

        Schema::table('assessment_run_items', function (Blueprint $table): void {
            $table->dropIndex(['failure_type']);
            $table->dropColumn(['failure_type', 'error_code', 'error_detail', 'last_attempt_duration_ms']);
        });

        Schema::table('assessment_runs', function (Blueprint $table): void {
            $table->dropColumn('service_errors');
        });
    }
};
