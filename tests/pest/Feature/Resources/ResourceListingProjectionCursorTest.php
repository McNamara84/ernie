<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Http\Resources\ResourceListItemResource;
use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\DateType;
use App\Models\Description;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceListingProjection;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
use App\Models\User;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Resources\ResourceListingProjectorService;
use App\Services\Resources\ResourceQueryBuilder;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
    actingAs(User::factory()->create(['email_verified_at' => now()]));
});

it('maintains denormalized listing values for resource and relation changes', function (): void {
    $curator = User::factory()->create(['name' => 'Projection Curator']);
    $person = Person::factory()->create(['given_name' => 'Ada', 'family_name' => 'Lovelace']);
    $resource = Resource::factory()->create([
        'access_level' => AccessLevel::OPEN,
        'created_by_user_id' => $curator->id,
        'updated_by_user_id' => null,
    ]);
    $title = Title::factory()->create([
        'resource_id' => $resource->id,
        'value' => 'Projected Climate Record',
    ]);
    ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    $resource->rights()->attach(Right::factory()->create());

    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $projection = ResourceListingProjection::query()->findOrFail($resource->id);
    expect($projection->workflow_status)->toBe('curation')
        ->and($projection->is_dashboard_draft)->toBeFalse()
        ->and($projection->main_title)->toBe('Projected Climate Record')
        ->and($projection->main_title_sort)->toBe('Projected Climate Record')
        ->and($projection->first_creator_sort)->toBe('Lovelace')
        ->and($projection->curator_name)->toBe('Projection Curator')
        ->and($projection->search_text)->toContain('projected climate record');

    $title->update(['value' => 'Updated Projection Title']);
    $person->update(['family_name' => 'Byron']);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    (new RefreshResourceListingProjectionsForDependencyJob(
        Person::class,
        $person->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );

    $projection->refresh();
    expect($projection->main_title)->toBe('Updated Projection Title')
        ->and($projection->main_title_sort)->toBe('Updated Projection Title')
        ->and($projection->first_creator_sort)->toBe('Byron');
});

it('stores a bounded title sort key while retaining the complete display title', function (): void {
    $title = str_repeat('Long title segment ', 40);
    $resource = Resource::factory()->create();
    Title::factory()->create(['resource_id' => $resource->id, 'value' => $title]);

    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $projection = ResourceListingProjection::query()->findOrFail($resource->id);
    expect($projection->main_title)->toBe($title)
        ->and($projection->main_title_sort)->toBe(mb_substr($title, 0, 512))
        ->and(mb_strlen($projection->main_title_sort))->toBe(512);
});

it('orders cursor queries by indexed projection keys and the projection resource id', function (string $sortKey, string $sortColumn): void {
    $query = app(ResourceQueryBuilder::class)->baseQuery();
    app(ResourceQueryBuilder::class)->applySorting($query, $sortKey, 'asc');

    expect($query->getQuery()->orders)->toBe(
        $sortColumn === 'listing_resource_id'
            ? [['column' => 'listing_resource_id', 'direction' => 'asc']]
            : [
                ['column' => $sortColumn, 'direction' => 'asc'],
                ['column' => 'listing_resource_id', 'direction' => 'asc'],
            ],
    );
})->with([
    'id' => ['id', 'listing_resource_id'],
    'DOI' => ['doi', 'listing_sort_doi'],
    'title' => ['title', 'listing_main_title_sort'],
    'resource type' => ['resourcetypegeneral', 'listing_resource_type_sort'],
    'first creator' => ['first_author', 'listing_first_creator_sort'],
    'year' => ['year', 'listing_sort_year'],
    'curator' => ['curator', 'listing_curator_name'],
    'status rank' => ['publicstatus', 'listing_workflow_status_rank'],
    'created date' => ['created_at', 'listing_created_sort'],
    'updated date' => ['updated_at', 'listing_updated_sort'],
]);

it('serves projected display fields without loading source-only relations', function (): void {
    $curator = User::factory()->create(['name' => 'Fast List Curator']);
    $resourceType = ResourceType::factory()->create(['name' => 'Dataset', 'slug' => 'dataset']);
    $resource = Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
        'created_by_user_id' => $curator->id,
        'updated_by_user_id' => null,
    ]);
    Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Projected row title']);
    $createdType = DateType::query()->create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    $updatedType = DateType::query()->create(['name' => 'Updated', 'slug' => 'Updated', 'is_active' => true]);
    ResourceDate::query()->create([
        'resource_id' => $resource->id,
        'date_type_id' => $createdType->id,
        'date_value' => '2020-01-02',
    ]);
    ResourceDate::query()->create([
        'resource_id' => $resource->id,
        'date_type_id' => $updatedType->id,
        'date_value' => '2025-03-04',
    ]);

    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $listedResource = app(ResourceQueryBuilder::class)->baseQuery()->findOrFail($resource->id);
    $payload = (new ResourceListItemResource($listedResource))->resolve(request());

    expect($listedResource->relationLoaded('dates'))->toBeFalse()
        ->and($listedResource->relationLoaded('descriptions'))->toBeFalse()
        ->and($listedResource->relationLoaded('resourceType'))->toBeFalse()
        ->and($listedResource->relationLoaded('createdBy'))->toBeFalse()
        ->and($listedResource->relationLoaded('updatedBy'))->toBeFalse()
        ->and($payload['created_at'])->toBe('2020-01-02')
        ->and($payload['updated_at'])->toBe('2025-03-04')
        ->and($payload['curator'])->toBe('Fast List Curator')
        ->and($payload['publicstatus'])->toBe($resource->fresh()->publicStatus())
        ->and($payload['resource_type'])->toBe(['name' => 'Dataset', 'slug' => 'dataset'])
        ->and($payload['title'])->toBe('Projected row title');
});

it('keeps the projection consistent across rollbacks, removals, and dependency changes', function (): void {
    $curator = User::factory()->create(['name' => 'Original Curator']);
    $resourceType = ResourceType::factory()->create(['name' => 'Original Type', 'slug' => 'original-type']);
    $person = Person::factory()->create(['family_name' => 'Original Creator']);
    $right = Right::factory()->create();
    $resource = Resource::factory()->create([
        'access_level' => AccessLevel::OPEN,
        'resource_type_id' => $resourceType->id,
        'created_by_user_id' => $curator->id,
    ]);
    $title = Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Original title']);
    ResourceCreator::factory()->forPerson($person)->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    $resource->rights()->attach($right);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    DB::beginTransaction();
    $title->update(['value' => 'Rolled-back title']);
    DB::rollBack();
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    expect(ResourceListingProjection::query()->findOrFail($resource->id)->main_title)->toBe('Original title');

    $curator->update(['name' => 'Updated Curator']);
    $resourceType->update(['name' => 'Updated Type']);
    $person->update(['family_name' => 'Updated Creator']);
    (new RefreshResourceListingProjectionsForDependencyJob(
        User::class,
        $curator->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
    (new RefreshResourceListingProjectionsForDependencyJob(
        ResourceType::class,
        $resourceType->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
    (new RefreshResourceListingProjectionsForDependencyJob(
        Person::class,
        $person->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );

    $projection = ResourceListingProjection::query()->findOrFail($resource->id);
    expect($projection->curator_name)->toBe('Updated Curator')
        ->and($projection->resource_type_sort)->toBe('Updated Type')
        ->and($projection->first_creator_sort)->toBe('Updated Creator')
        ->and($projection->workflow_status)->toBe('curation');

    $right->delete();
    (new RefreshResourceListingProjectionsForDependencyJob(
        Right::class,
        $right->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_DELETED,
    ))->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
    expect(ResourceListingProjection::query()->findOrFail($resource->id)->workflow_status)->toBe('draft');

    $resource->delete();
    expect(ResourceListingProjection::query()->find($resource->id))->toBeNull();
});

it('rebuilds missing projection rows in bounded batches', function (): void {
    $resources = Resource::factory()->count(3)->create();
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    ResourceListingProjection::query()->delete();

    app(ResourceListingProjectorService::class)->rebuildAll();

    expect(ResourceListingProjection::query()->count())->toBe(3)
        ->and(ResourceListingProjection::query()->pluck('resource_id')->sort()->values()->all())
        ->toBe($resources->modelKeys());
});

it('paginates forward with opaque cursors without duplicates', function (): void {
    $resources = Resource::factory()->count(5)->create()->sortBy('id')->values();

    $first = $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('pagination.has_more', true)
        ->assertJsonMissingPath('pagination.total');

    $firstIds = collect($first->json('resources'))->pluck('id')->all();
    $cursor = $first->json('pagination.next_cursor');
    expect($cursor)->toBeString()->not->toBeEmpty();

    $second = $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=asc&cursor='.urlencode($cursor))
        ->assertOk();
    $secondIds = collect($second->json('resources'))->pluck('id')->all();

    expect($firstIds)->toBe($resources->take(2)->pluck('id')->all())
        ->and($secondIds)->toBe($resources->slice(2, 2)->pluck('id')->all())
        ->and(array_intersect($firstIds, $secondIds))->toBeEmpty();
});

it('paginates in descending order with the same deterministic tie breaker', function (): void {
    $resources = Resource::factory()->count(5)->create()->sortByDesc('id')->values();

    $first = $this->getJson('/resources/load-more?per_page=3&sort_key=id&sort_direction=desc')->assertOk();
    $cursor = (string) $first->json('pagination.next_cursor');
    $second = $this->getJson('/resources/load-more?per_page=3&sort_key=id&sort_direction=desc&cursor='.urlencode($cursor))->assertOk();

    $ids = collect($first->json('resources'))->concat($second->json('resources'))->pluck('id')->all();
    expect($ids)->toBe($resources->pluck('id')->all())
        ->and(array_unique($ids))->toHaveCount(5);
});

it('uses resource id as tie breaker and supports missing source sort values', function (): void {
    $sameTimestamp = now()->startOfSecond();
    $resources = collect([
        Resource::factory()->create(['publication_year' => null, 'updated_at' => $sameTimestamp]),
        Resource::factory()->create(['publication_year' => null, 'updated_at' => $sameTimestamp]),
        Resource::factory()->create(['publication_year' => 2024, 'updated_at' => $sameTimestamp]),
    ])->sortBy('id')->values();

    $first = $this->getJson('/resources/load-more?per_page=2&sort_key=year&sort_direction=asc')->assertOk();
    $cursor = (string) $first->json('pagination.next_cursor');
    $second = $this->getJson('/resources/load-more?per_page=2&sort_key=year&sort_direction=asc&cursor='.urlencode($cursor))->assertOk();

    $ids = collect($first->json('resources'))
        ->concat($second->json('resources'))
        ->pluck('id')
        ->all();

    expect($ids)->toBe($resources->pluck('id')->all())
        ->and(array_unique($ids))->toHaveCount(3);
});

it('rejects manipulated cursors and cursors from another sort context', function (): void {
    Resource::factory()->count(3)->create();

    $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=asc&cursor=manipulated')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);

    $first = $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=asc')->assertOk();
    $cursor = (string) $first->json('pagination.next_cursor');

    $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=desc&cursor='.urlencode($cursor))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);

    $this->getJson('/resources/load-more?per_page=3&sort_key=id&sort_direction=asc&cursor='.urlencode($cursor))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);

    $this->getJson('/resources/load-more?per_page=2&sort_key=id&sort_direction=asc&search=different&cursor='.urlencode($cursor))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('resolves exact totals only through the independent count endpoint', function (): void {
    Resource::factory()->count(4)->create();

    $this->get('/resources?per_page=2')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pagination.total', null)
            ->where('pagination.count_status', 'pending')
            ->where('pagination.has_more', true)
            ->where('pagination.filter_fingerprint', fn ($value): bool => is_string($value) && strlen($value) === 64)
        );

    $this->getJson('/resources/count?per_page=2')
        ->assertOk()
        ->assertJsonPath('total', 4)
        ->assertJsonPath('count_status', 'ready')
        ->assertJsonPath('filter_fingerprint', fn ($value): bool => is_string($value) && strlen($value) === 64);
});

it('keeps count fingerprints independent from sort and page size but binds them to filters', function (): void {
    Resource::factory()->count(2)->create();

    $first = $this->getJson('/resources/count?per_page=1&sort_key=title&sort_direction=asc')->assertOk();
    $sameFilters = $this->getJson('/resources/count?per_page=100&sort_key=updated_at&sort_direction=desc')->assertOk();
    $differentFilters = $this->getJson('/resources/count?search=unlikely-search-term')->assertOk();

    expect($first->json('filter_fingerprint'))->toBe($sameFilters->json('filter_fingerprint'))
        ->and($differentFilters->json('filter_fingerprint'))->not->toBe($first->json('filter_fingerprint'));
});

it('returns a recoverable failed count response when the count lock times out', function (): void {
    Resource::factory()->create();
    $lock = Mockery::mock();
    $originalCacheManager = Cache::getFacadeRoot();
    $cacheManager = Mockery::mock(CacheManager::class, [app()])->makePartial();

    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
    $cacheManager->shouldReceive('lock')->once()->andReturn($lock);
    Cache::swap($cacheManager);

    try {
        $this->getJson('/resources/count?search=lock-timeout-case')
            ->assertStatus(503)
            ->assertJsonPath('total', null)
            ->assertJsonPath('count_status', 'failed')
            ->assertJsonPath('filter_fingerprint', fn ($value): bool => is_string($value) && strlen($value) === 64);
    } finally {
        Cache::swap($originalCacheManager);
    }
});

it('does not execute an exact projection count in the initial list request', function (): void {
    Resource::factory()->count(3)->create();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $this->get('/resources')->assertOk();

    expect(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'resource_listing_projections')
            && preg_match('/\bcount\s*\(/', $sql) === 1,
    ))->toBeEmpty();
});

it('caches filter options and invalidates them when projected resources change', function (): void {
    $firstType = ResourceType::factory()->create(['name' => 'First Type', 'slug' => 'first-type']);
    Resource::factory()->create(['resource_type_id' => $firstType->id]);

    $first = $this->getJson('/resources/filter-options')->assertOk();
    expect(collect($first->json('resource_types'))->pluck('slug'))->toContain('first-type');

    // Warm the cache before adding a resource with a previously unused type.
    $this->getJson('/resources/filter-options')->assertOk();
    $secondType = ResourceType::factory()->create(['name' => 'Second Type', 'slug' => 'second-type']);
    Resource::factory()->create(['resource_type_id' => $secondType->id]);

    $updated = $this->getJson('/resources/filter-options')->assertOk();
    expect(collect($updated->json('resource_types'))->pluck('slug'))
        ->toContain('first-type', 'second-type');
});
