<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds columns to landing_pages for external landing page support (Issue #540).
     *
     * When template='external', the landing page redirects (301) to a composed
     * external URL instead of rendering an internal template. The URL is built
     * from external_domain_id (FK to landing_page_domains) + external_path.
     *
     * restrictOnDelete prevents deleting a domain that is still in use.
     */
    public function up(): void
    {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->foreignId('external_domain_id')
                ->nullable()
                ->after('ftp_url')
                ->constrained('landing_page_domains')
                ->restrictOnDelete();

            $table->string('external_path', 2048)
                ->nullable()
                ->after('external_domain_id');
        });
    }
};
