<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
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

function resourceListingProjectionPartyNameSearchMigration(): Migration
{
    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_09_18_000001_add_party_name_search_text_to_resource_listing_projections.php');

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

it('adds a name-only party projection without exposing emails and removes only that column on rollback', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Public', 'family_name' => 'Researcher']);
    ResourceCreator::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'name_snapshot' => 'Researcher, Public A.',
        'given_name_snapshot' => 'Public A.',
        'family_name_snapshot' => 'Researcher',
        'email' => 'private-creator@example.test',
    ]);
    $institution = Institution::factory()->create(['name' => 'Public Data Institute']);
    ResourceContributor::factory()->forInstitution($institution)->create([
        'resource_id' => $resource->id,
        'email' => 'private-contributor@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $migration = resourceListingProjectionPartyNameSearchMigration();
    $migration->down();

    try {
        expect(Schema::hasColumn('resource_listing_projections', 'party_name_search_text'))->toBeFalse();

        $migration->up();

        $projection = ResourceListingProjection::query()->findOrFail($resource->id);

        expect(Schema::hasColumn('resource_listing_projections', 'party_name_search_text'))->toBeTrue()
            ->and($projection->party_name_search_text)
            ->toContain('public a researcher')
            ->toContain('researcherpublica')
            ->toContain('public data institute')
            ->not->toContain('private-creator@example.test')
            ->not->toContain('private-contributor@example.test')
            ->and($projection->party_search_text)
            ->toContain('private-creator@example.test')
            ->toContain('private-contributor@example.test');

        $migration->down();

        expect(Schema::hasTable('resource_listing_projections'))->toBeTrue()
            ->and(Schema::hasColumn('resource_listing_projections', 'party_search_text'))->toBeTrue()
            ->and(Schema::hasColumn('resource_listing_projections', 'party_name_search_text'))->toBeFalse();
    } finally {
        $migration->up();
    }
});

it('retries the name-only backfill when the column already exists', function (): void {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create(['given_name' => 'Retry', 'family_name' => 'PublicName']);
    ResourceContributor::factory()->forPerson($person)->create([
        'resource_id' => $resource->id,
        'email' => 'retry-private@example.test',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    ResourceListingProjection::query()->whereKey($resource->id)->update(['party_name_search_text' => null]);

    resourceListingProjectionPartyNameSearchMigration()->up();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_name_search_text)
        ->toContain('retry publicname')
        ->not->toContain('retry-private@example.test');
});
