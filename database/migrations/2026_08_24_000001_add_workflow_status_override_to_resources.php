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
        Schema::table('resources', function (Blueprint $table): void {
            $table->string('workflow_status_override', 20)
                ->nullable()
                ->after('force_review_status');
            $table->index('workflow_status_override');
        });

        DB::table('resources')
            ->where('force_review_status', true)
            ->whereNull('workflow_status_override')
            ->update(['workflow_status_override' => 'review']);
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropIndex(['workflow_status_override']);
            $table->dropColumn('workflow_status_override');
        });
    }
};
