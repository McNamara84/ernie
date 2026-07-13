<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('landing_pages', 'downloads_unavailable')) {
            return;
        }

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->boolean('downloads_unavailable')
                ->default(false)
                ->after('ftp_url');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('landing_pages', 'downloads_unavailable')) {
            return;
        }

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('downloads_unavailable');
        });
    }
};
