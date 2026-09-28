<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resource_assessment_refreshes', function (Blueprint $table): void {
            $table->timestamp('requested_at', 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resource_assessment_refreshes', function (Blueprint $table): void {
            $table->timestamp('requested_at')->change();
        });
    }
};
