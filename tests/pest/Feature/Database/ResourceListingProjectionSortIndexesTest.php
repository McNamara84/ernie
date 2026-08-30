<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function loadResourceListingProjectionSortIndexesMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_08_30_000001_add_resource_listing_projection_sort_indexes.php');

    return $migration;
}

it('defines a composite projection index for every supported cursor sort order', function (): void {
    $expectedIndexes = [
        'rlp_id_sort_idx' => ['is_igsn', 'resource_id'],
        'rlp_doi_sort_idx' => ['is_igsn', 'sort_doi', 'resource_id'],
        'rlp_title_sort_idx' => ['is_igsn', 'main_title_sort', 'resource_id'],
        'rlp_creator_sort_idx' => ['is_igsn', 'first_creator_sort', 'resource_id'],
        'rlp_resource_type_sort_idx' => ['is_igsn', 'resource_type_sort', 'resource_id'],
        'rlp_curator_name_sort_idx' => ['is_igsn', 'curator_name', 'resource_id'],
        'rlp_status_rank_sort_idx' => ['is_igsn', 'workflow_status_rank', 'resource_id'],
        'rlp_year_sort_idx' => ['is_igsn', 'sort_year', 'resource_id'],
        'rlp_created_sort_idx' => ['is_igsn', 'created_sort', 'resource_id'],
        'rlp_default_sort_idx' => ['is_igsn', 'updated_sort', 'resource_id'],
    ];
    $indexes = collect(Schema::getIndexes('resource_listing_projections'))->keyBy('name');

    foreach ($expectedIndexes as $name => $columns) {
        expect($indexes->has($name))->toBeTrue("Missing cursor sort index [{$name}]")
            ->and($indexes->get($name)['columns'] ?? null)->toBe($columns);
    }
});

it('repairs an already-created projection table and retains its baseline index on rollback', function (): void {
    $title = str_repeat('Existing projection title ', 30);
    $resource = Resource::factory()->create();
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    ResourceListingProjection::query()->whereKey($resource->id)->update([
        'main_title' => $title,
        'main_title_sort' => 'stale',
    ]);
    $migration = loadResourceListingProjectionSortIndexesMigration();

    $migration->down();

    try {
        expect(Schema::hasColumn('resource_listing_projections', 'main_title_sort'))->toBeFalse()
            ->and(Schema::hasIndex('resource_listing_projections', 'rlp_default_sort_idx'))->toBeTrue();

        Schema::table('resource_listing_projections', function (Blueprint $table): void {
            $table->dropIndex('rlp_default_sort_idx');
        });

        expect(Schema::hasIndex('resource_listing_projections', 'rlp_default_sort_idx'))->toBeFalse();

        $migration->up();

        $defaultSortIndex = collect(Schema::getIndexes('resource_listing_projections'))
            ->firstWhere('name', 'rlp_default_sort_idx');

        expect(Schema::hasColumn('resource_listing_projections', 'main_title_sort'))->toBeTrue()
            ->and($defaultSortIndex['columns'] ?? null)->toBe(['is_igsn', 'updated_sort', 'resource_id'])
            ->and(ResourceListingProjection::query()->whereKey($resource->id)->value('main_title_sort'))
            ->toBe(mb_substr($title, 0, 512));
    } finally {
        $migration->up();
    }
});
