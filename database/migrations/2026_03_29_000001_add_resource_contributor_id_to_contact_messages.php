<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add resource_contributor_id FK to contact_messages table.
     *
     * Tracks which contributor contact person received the message,
     * complementing the existing resource_creator_id column for audit
     * and rate-limit analysis.
     */
    public function up(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->foreignId('resource_contributor_id')
                ->nullable()
                ->after('resource_creator_id')
                ->constrained('resource_contributors')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contact_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('resource_contributor_id');
        });
    }
};
