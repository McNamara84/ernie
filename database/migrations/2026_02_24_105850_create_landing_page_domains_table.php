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
     * Creates the landing_page_domains table for managing allowed domains
     * used in external landing page URLs (Issue #540).
     *
     * Users with access to /settings can manage these domains. When a resource
     * uses an "External Landing Page" template, the external URL is composed
     * from a domain (selected from this table) and a free-text path.
     */
    public function up(): void
    {
        Schema::create('landing_page_domains', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 2048)->unique();
            $table->timestamps();
        });
    }
};
