<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->timestamp('legacy_description_breaks_normalized_at')
                ->nullable()
                ->after('legacy_source_status');
        });
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropColumn('legacy_description_breaks_normalized_at');
        });
    }
};
