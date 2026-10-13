<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Services\PortalCacheInvalidationService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_party_name_terms', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->text('term');
            $table->index('resource_id');
        });

        // Rebuild in bounded resource batches before IGSN searches use this table.
        app(ResourceListingProjectorService::class)->rebuildAll();
        app(PortalCacheInvalidationService::class)->schedule(
            [PortalScope::IGSN],
            [
                PortalCacheArea::PAGE,
                PortalCacheArea::COUNT,
                PortalCacheArea::IGSN_FACETS,
                PortalCacheArea::MAP_PAYLOAD,
                PortalCacheArea::MAP_EXTENT,
            ],
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_party_name_terms');
    }
};
