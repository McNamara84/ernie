<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Create tables for OAI-PMH 2.0 harvesting endpoint.
 *
 * - oai_pmh_deleted_records: Permanent tracking of deleted/depublished records
 * - oai_pmh_resumption_tokens: Cursor-based pagination tokens
 */
return new class extends Migration
{
    public function up(): void
    {
        // Tracks permanently deleted/depublished records for OAI-PMH
        // persistent deleted record support.
        Schema::create('oai_pmh_deleted_records', function (Blueprint $table): void {
            $table->id();
            $table->string('oai_identifier')->unique();
            $table->string('doi');
            $table->timestamp('datestamp');
            $table->json('sets')->nullable();
            $table->timestamps();

            $table->index('datestamp');
            $table->index('doi');
        });

        // Stores cursor-based resumption tokens for paginated OAI-PMH responses.
        Schema::create('oai_pmh_resumption_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('verb');
            $table->string('metadata_prefix')->nullable();
            $table->string('set_spec')->nullable();
            $table->timestamp('from_date')->nullable();
            $table->timestamp('until_date')->nullable();
            $table->unsignedBigInteger('cursor');
            $table->unsignedBigInteger('complete_list_size');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oai_pmh_resumption_tokens');
        Schema::dropIfExists('oai_pmh_deleted_records');
    }
};
