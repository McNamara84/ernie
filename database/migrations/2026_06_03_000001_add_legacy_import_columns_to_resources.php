<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->string('legacy_source', 50)->nullable()->after('updated_by_user_id');
            $table->unsignedBigInteger('legacy_source_id')->nullable()->after('legacy_source');
            $table->string('legacy_source_status', 50)->nullable()->after('legacy_source_id');
            $table->boolean('force_review_status')->default(false)->after('legacy_source_status');

            $table->index(['legacy_source', 'legacy_source_id'], 'resources_legacy_source_lookup_idx');
            $table->index('legacy_source_status');
            $table->index('force_review_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropIndex('resources_legacy_source_lookup_idx');
            $table->dropIndex(['legacy_source_status']);
            $table->dropIndex(['force_review_status']);

            $table->dropColumn([
                'legacy_source',
                'legacy_source_id',
                'legacy_source_status',
                'force_review_status',
            ]);
        });
    }
};
