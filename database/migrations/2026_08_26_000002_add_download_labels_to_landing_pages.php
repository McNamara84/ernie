<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->string('primary_download_label')->nullable()->after('ftp_url');
        });

        Schema::table('landing_page_files', function (Blueprint $table): void {
            $table->string('label')->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('landing_page_files', function (Blueprint $table): void {
            $table->dropColumn('label');
        });

        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('primary_download_label');
        });
    }
};
