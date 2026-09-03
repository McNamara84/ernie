<?php

declare(strict_types=1);

use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string TABLE = 'resource_listing_projections';

    private const string INDEX = 'rlp_spdx_license_idx';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if (! Schema::hasColumn(self::TABLE, 'has_spdx_license')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->boolean('has_spdx_license')->default(false)->after('is_igsn');
            });
        }

        app(ResourceListingProjectorService::class)->rebuildAll();

        if (! Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(
                    ['is_igsn', 'has_spdx_license', 'updated_sort', 'resource_id'],
                    self::INDEX,
                );
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, 'has_spdx_license')) {
            return;
        }

        if (Schema::hasIndex(self::TABLE, self::INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn('has_spdx_license');
        });
    }
};
