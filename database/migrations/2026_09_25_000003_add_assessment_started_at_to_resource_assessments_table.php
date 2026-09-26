<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_assessments', function (Blueprint $table): void {
            $table->timestamp('assessment_started_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resource_assessments', function (Blueprint $table): void {
            $table->dropColumn('assessment_started_at');
        });
    }
};
