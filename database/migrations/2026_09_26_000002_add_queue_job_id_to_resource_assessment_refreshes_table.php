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
        Schema::table('resource_assessment_refreshes', function (Blueprint $table): void {
            $table->unsignedBigInteger('queue_job_id')->nullable();
        });

        // Queued rows from the previous release have a one-day lease and no
        // database job ID. Let the regular recovery tick replace them promptly.
        DB::table('resource_assessment_refreshes')
            ->where('status', 'queued')
            ->update(['lease_expires_at' => now()->subSecond()]);
    }

    public function down(): void
    {
        Schema::table('resource_assessment_refreshes', function (Blueprint $table): void {
            $table->dropColumn('queue_job_id');
        });
    }
};
