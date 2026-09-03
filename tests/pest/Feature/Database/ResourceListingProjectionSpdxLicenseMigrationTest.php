<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Models\Right;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function resourceListingProjectionSpdxLicenseMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_03_000001_add_has_spdx_license_to_resource_listing_projections.php');

    return $migration;
}

function createResourceListingProjectionMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_29_000002_create_resource_listing_projections.php');

    return $migration;
}

it('backfills the SPDX license projection and creates its composite index', function (): void {
    $spdxRight = Right::factory()->create(['identifier' => 'CC-BY-4.0-MIGRATION']);
    $customRight = Right::factory()->create([
        'identifier' => 'CUSTOM-MIGRATION-LICENSE',
        'scheme_uri' => null,
    ]);
    $withSpdx = Resource::factory()->create(['doi' => '10.5880/migration-with-spdx']);
    $withoutSpdx = Resource::factory()->create(['doi' => '10.5880/migration-without-spdx']);
    $withSpdx->rights()->attach($spdxRight);
    $withoutSpdx->rights()->attach($customRight);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    ResourceListingProjection::query()->whereKey($withSpdx->id)->update(['has_spdx_license' => false]);
    ResourceListingProjection::query()->whereKey($withoutSpdx->id)->update(['has_spdx_license' => true]);

    $migration = resourceListingProjectionSpdxLicenseMigration();
    $migration->down();

    try {
        expect(Schema::hasColumn('resource_listing_projections', 'has_spdx_license'))->toBeFalse()
            ->and(Schema::hasIndex('resource_listing_projections', 'rlp_spdx_license_idx'))->toBeFalse();

        $migration->up();

        $index = collect(Schema::getIndexes('resource_listing_projections'))
            ->firstWhere('name', 'rlp_spdx_license_idx');

        expect(Schema::hasColumn('resource_listing_projections', 'has_spdx_license'))->toBeTrue()
            ->and($index['columns'] ?? null)
            ->toBe(['is_igsn', 'has_spdx_license', 'updated_sort', 'resource_id'])
            ->and(ResourceListingProjection::query()->findOrFail($withSpdx->id)->has_spdx_license)->toBeTrue()
            ->and(ResourceListingProjection::query()->findOrFail($withoutSpdx->id)->has_spdx_license)->toBeFalse();
    } finally {
        $migration->up();
    }
});

it('keeps the SPDX column and index owned by only the additive migration', function (): void {
    $spdxRight = Right::factory()->create(['identifier' => 'CC-BY-4.0-FRESH-MIGRATION']);
    $resource = Resource::factory()->create(['doi' => '10.5880/fresh-migration-with-spdx']);
    $resource->rights()->attach($spdxRight);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $additiveMigration = resourceListingProjectionSpdxLicenseMigration();
    $createMigration = createResourceListingProjectionMigration();

    $additiveMigration->down();
    $createMigration->down();

    try {
        $createMigration->up();

        expect(Schema::hasTable('resource_listing_projections'))->toBeTrue()
            ->and(Schema::hasColumn('resource_listing_projections', 'has_spdx_license'))->toBeFalse()
            ->and(Schema::hasIndex('resource_listing_projections', 'rlp_spdx_license_idx'))->toBeFalse()
            ->and(ResourceListingProjection::query()->count())->toBe(0);

        $additiveMigration->up();

        expect(Schema::hasColumn('resource_listing_projections', 'has_spdx_license'))->toBeTrue()
            ->and(Schema::hasIndex('resource_listing_projections', 'rlp_spdx_license_idx'))->toBeTrue()
            ->and(ResourceListingProjection::query()->findOrFail($resource->id)->has_spdx_license)->toBeTrue();

        $additiveMigration->down();

        expect(Schema::hasTable('resource_listing_projections'))->toBeTrue()
            ->and(Schema::hasColumn('resource_listing_projections', 'has_spdx_license'))->toBeFalse()
            ->and(Schema::hasIndex('resource_listing_projections', 'rlp_spdx_license_idx'))->toBeFalse();
    } finally {
        $additiveMigration->up();
    }
});
