<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceListingProjection;
use App\Services\PortalCacheInvalidationService;
use App\Services\PortalCacheVersionService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

it('backfills party projections before the tombstone migration and recognizes tombstones afterwards', function (string $filename, string $column, bool $columnExists): void {
    $published = Resource::factory()->create(['doi' => '10.5880/pre-tombstone-migration']);
    $publishedPage = LandingPage::factory()->published()->create(['resource_id' => $published->id]);
    $draft = Resource::factory()->create(['doi' => null, 'publication_year' => null]);
    LandingPage::factory()->draft()->create(['resource_id' => $draft->id]);
    $person = Person::factory()->create(['given_name' => 'Upgrade', 'family_name' => 'Researcher']);
    ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $published->id]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    /** @var Migration $migration */
    $migration = require database_path('migrations/'.$filename);
    if ($columnExists) {
        ResourceListingProjection::query()->whereKey($published->id)->update([$column => null]);
    } else {
        $migration->down();
    }

    Schema::table('landing_pages', function (Blueprint $table): void {
        $table->dropIndex(['is_tombstone']);
        $table->dropColumn('is_tombstone');
    });

    try {
        expect(Schema::hasColumn('resource_listing_projections', $column))->toBe($columnExists);

        $migration->up();

        expect(Schema::hasColumn('landing_pages', 'is_tombstone'))->toBeFalse()
            ->and(Schema::hasColumn('resource_listing_projections', $column))->toBeTrue()
            ->and(ResourceListingProjection::query()->findOrFail($published->id)->getAttribute($column))
            ->toContain('upgrade researcher')
            ->and(ResourceListingProjection::query()->findOrFail($published->id)->workflow_status)->toBe('published')
            ->and(ResourceListingProjection::query()->findOrFail($draft->id)->workflow_status)->toBe('draft');
    } finally {
        Schema::table('landing_pages', function (Blueprint $table): void {
            $table->boolean('is_tombstone')->default(false)->index();
        });
        $migration->up();
    }

    DB::table('landing_pages')->where('id', $publishedPage->id)->update(['is_tombstone' => true]);
    app(ResourceListingProjectorService::class)->rebuildAll();

    expect(ResourceListingProjection::query()->findOrFail($published->id)->workflow_status)->toBe('dead')
        ->and(ResourceListingProjection::query()->findOrFail($published->id)->workflow_status_rank)->toBe(5);
})->with([
    'party search' => ['2026_09_11_000002_add_party_search_text_to_resource_listing_projections.php', 'party_search_text'],
    'name-only party search' => ['2026_09_18_000001_add_party_name_search_text_to_resource_listing_projections.php', 'party_name_search_text'],
])->with([
    'first migration attempt' => false,
    'retry after column creation' => true,
]);

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

    Cache::flush();
    app()->forgetInstance(PortalCacheInvalidationService::class);
    $versions = app(PortalCacheVersionService::class);
    $cacheKeys = [
        CacheKey::PORTAL_PAGE_PAYLOAD,
        CacheKey::PORTAL_LISTING_COUNT,
        CacheKey::PORTAL_MAP_PAYLOAD,
        CacheKey::PORTAL_MAP_EXTENT,
    ];
    foreach ($cacheKeys as $cacheKey) {
        expect($versions->current($cacheKey, PortalScope::DOI))->toBe(1);
    }

    resourceListingProjectionPartyNameSearchMigration()->up();
    app(PortalCacheInvalidationService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->party_name_search_text)
        ->toContain('retry publicname')
        ->not->toContain('retry-private@example.test');
    foreach ($cacheKeys as $cacheKey) {
        expect($versions->current($cacheKey, PortalScope::DOI))->toBe(2);
    }
});
