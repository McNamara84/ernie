<?php

declare(strict_types=1);

use App\Services\EmbargoService;
use App\Services\ListingCountService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resources', function (Blueprint $table): void {
            // Null distinguishes create attempts from the previous Open-on-registration workflow.
            $table->string('embargo_registration_access_level', 32)->nullable();
            $table->boolean('data_embargo_sync_pending')->default(false);
        });
        Schema::create('data_embargo_notifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained()->cascadeOnDelete();
            $table->date('available_date');
            $table->string('status', 24)->default('pending');
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['resource_id', 'available_date']);
            $table->index(['status', 'created_at']);
        });

        // Persist the day-based embargo before midnight can turn an inconsistent
        // legacy Open record into an accidental automatic data release.
        App\Models\Resource::query()->with(['dates.dateType', 'landingPage'])->chunkById(200, function ($resources): void {
            foreach ($resources as $resource) {
                app(EmbargoService::class)->normalizeResource($resource);
            }
        });
        app(ResourceListingProjectorService::class)->rebuildAll();
        app(ListingCountService::class)->scheduleInternalInvalidationAfterCommit();
    }

    public function down(): void
    {
        Schema::dropIfExists('data_embargo_notifications');
        Schema::table('resources', function (Blueprint $table): void {
            $table->dropColumn(['embargo_registration_access_level', 'data_embargo_sync_pending']);
        });
    }
};
