<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add contact_url column to landing_pages table.
 *
 * This column allows landing pages to display a "Request data via contact form"
 * button when no direct download URL (ftp_url) is configured. This is useful
 * for large datasets that are distributed via contact form instead of FTP.
 *
 * Related to Issue #373: Download URL (FTP) not a mandatory field
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->string('contact_url', 2048)->nullable()->after('ftp_url');
        });
    }

    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->dropColumn('contact_url');
        });
    }
};
