<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceListingProjection;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

uses()->group('database', 'mysql-sensitive');

function resourceListingProjectionPartySearchMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_11_000002_add_party_search_text_to_resource_listing_projections.php');

    return $migration;
}

it('adds and backfills the party search projection and removes it on rollback', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Migration', 'family_name' => 'Person']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'migration@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $migration = resourceListingProjectionPartySearchMigration();
    $migration->down();

    try {
        expect(Schema::hasColumn('resource_listing_projections', 'party_search_text'))->toBeFalse();

        $migration->up();

        expect(Schema::hasColumn('resource_listing_projections', 'party_search_text'))->toBeTrue()
            ->and(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
            ->toContain('migration person')
            ->toContain('migration@example.test');

        $migration->down();

        expect(Schema::hasTable('resource_listing_projections'))->toBeTrue()
            ->and(Schema::hasColumn('resource_listing_projections', 'party_search_text'))->toBeFalse();
    } finally {
        $migration->up();
    }
});

it('retries the backfill when the party search column already exists', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Retry', 'family_name' => 'Backfill']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'retry@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    ResourceListingProjection::query()->whereKey($resource->id)->update(['party_search_text' => null]);

    resourceListingProjectionPartySearchMigration()->up();

    expect(Schema::hasColumn('resource_listing_projections', 'party_search_text'))->toBeTrue()
        ->and(ResourceListingProjection::query()->findOrFail($resource->id)->party_search_text)
        ->toContain('retry backfill')
        ->toContain('retry@example.test');
});
