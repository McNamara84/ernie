<?php

declare(strict_types=1);

use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resource_listing_projections')) {
            return;
        }

        if (! Schema::hasColumn('resource_listing_projections', 'party_name_search_text')) {
            $afterColumn = Schema::hasColumn('resource_listing_projections', 'party_search_text')
                ? 'party_search_text'
                : 'search_text';

            Schema::table('resource_listing_projections', function (Blueprint $table) use ($afterColumn): void {
                $table->text('party_name_search_text')->nullable()->after($afterColumn);
            });
        }

        app(ResourceListingProjectorService::class)->rebuildAll();
    }

    public function down(): void
    {
        if (! Schema::hasTable('resource_listing_projections')
            || ! Schema::hasColumn('resource_listing_projections', 'party_name_search_text')) {
            return;
        }

        Schema::table('resource_listing_projections', function (Blueprint $table): void {
            $table->dropColumn('party_name_search_text');
        });
    }
};
