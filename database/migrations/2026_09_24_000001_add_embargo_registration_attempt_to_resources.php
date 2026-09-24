<?php

declare(strict_types=1);

use App\Services\ListingCountService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $needsStartedAt = ! Schema::hasColumn('resources', 'embargo_registration_started_at');
        $needsPrefix = ! Schema::hasColumn('resources', 'embargo_registration_prefix');

        if ($needsStartedAt || $needsPrefix) {
            Schema::table('resources', function (Blueprint $table) use ($needsStartedAt, $needsPrefix): void {
                if ($needsStartedAt) {
                    $table->timestamp('embargo_registration_started_at')->nullable();
                }
                if ($needsPrefix) {
                    $table->string('embargo_registration_prefix', 64)->nullable();
                }
            });
        }

        // Existing rows must receive the new Embargo status and published sort rank.
        app(ResourceListingProjectorService::class)->rebuildAll();
        app(ListingCountService::class)->scheduleInternalInvalidationAfterCommit();
    }

    public function down(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropColumn(['embargo_registration_started_at', 'embargo_registration_prefix']);
        });
    }
};
