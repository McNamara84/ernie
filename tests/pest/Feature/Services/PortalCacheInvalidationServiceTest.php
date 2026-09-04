<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\PortalCacheInvalidationService;
use App\Services\PortalCacheVersionService;
use App\Services\PortalSearchService;
use App\Support\PortalCacheNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

covers(PortalCacheInvalidationService::class);

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    app()->forgetInstance(PortalCacheInvalidationService::class);
});

it('does not invalidate public caches for a draft resource', function (): void {
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create());
    $versions = app(PortalCacheVersionService::class);
    $service = app(PortalCacheInvalidationService::class);

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1);

    $service->scheduleForResourceId($resource->id, [PortalCacheArea::PAGE]);
    $service->flushPending();

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1);
});

it('invalidates only the requested area and published portal scope', function (): void {
    $resourceType = ResourceType::withoutEvents(fn (): ResourceType => ResourceType::factory()->create([
        'slug' => PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE,
    ]));
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
    ]));
    LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]));
    Cache::flush();

    $versions = app(PortalCacheVersionService::class);
    $service = app(PortalCacheInvalidationService::class);
    $versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI);
    $versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::IGSN);
    $versions->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::IGSN);

    $service->scheduleForResourceId($resource->id, [PortalCacheArea::PAGE]);
    $service->flushPending();

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::IGSN))->toBe(2)
        ->and($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1)
        ->and($versions->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::IGSN))->toBe(1);
});

it('coalesces duplicate invalidations before they are flushed', function (): void {
    $versions = app(PortalCacheVersionService::class);
    $service = app(PortalCacheInvalidationService::class);
    $versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI);

    DB::transaction(function () use ($service): void {
        $service->schedule([PortalScope::DOI], [PortalCacheArea::PAGE]);
        $service->schedule([PortalScope::DOI], [PortalCacheArea::PAGE]);

        expect(app(PortalCacheVersionService::class)->current(
            CacheKey::PORTAL_PAGE_PAYLOAD,
            PortalScope::DOI,
        ))->toBe(1);
    });

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(2);
});

it('discards a pending invalidation when its transaction rolls back', function (): void {
    $versions = app(PortalCacheVersionService::class);
    $service = app(PortalCacheInvalidationService::class);
    $versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI);

    DB::beginTransaction();
    $service->schedule([PortalScope::DOI], [PortalCacheArea::PAGE]);
    DB::rollBack();
    $service->flushPending();

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1);
});

it('makes a late stale facet refresh unreachable after invalidation', function (): void {
    $resourceType = ResourceType::withoutEvents(fn (): ResourceType => ResourceType::factory()->create([
        'name' => 'Dataset',
        'slug' => 'dataset',
    ]));
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create([
        'resource_type_id' => $resourceType->id,
    ]));
    LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]));
    Cache::flush();

    $cacheKey = CacheKey::PORTAL_RESOURCE_TYPE_FACETS;
    $scope = PortalScope::DOI;
    $versions = app(PortalCacheVersionService::class);
    $oldKey = PortalCacheNamespace::versionedKey($cacheKey, $scope, $versions->current($cacheKey, $scope));
    $repository = method_exists(Cache::getStore(), 'tags')
        ? Cache::tags(PortalCacheNamespace::tags($cacheKey, $scope))
        : Cache::store();

    app(PortalCacheInvalidationService::class)->schedule([$scope], [PortalCacheArea::RESOURCE_TYPE_FACETS]);

    // Simulate a deferred refresh that finishes after the invalidation.
    $repository->put($oldKey, [['slug' => 'stale', 'name' => 'Stale', 'count' => 99]], $cacheKey->ttl());

    expect($versions->current($cacheKey, $scope))->toBe(2)
        ->and(app(PortalSearchService::class)->getResourceTypeFacets($scope))->toBe([[
            'slug' => 'dataset',
            'name' => 'Dataset',
            'count' => 1,
        ]]);
});

it('memoizes publication and scope lookups while invalidations are pending', function (): void {
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create());
    LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]));
    $service = app(PortalCacheInvalidationService::class);

    DB::beginTransaction();
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $service->scheduleForResourceId($resource->id, [PortalCacheArea::PAGE]);
        $queriesAfterFirstLookup = count(DB::getQueryLog());

        foreach (range(1, 20) as $_) {
            $service->scheduleForResourceId($resource->id, [PortalCacheArea::COUNT]);
        }

        expect($queriesAfterFirstLookup)->toBeGreaterThan(0)
            ->and(DB::getQueryLog())->toHaveCount($queriesAfterFirstLookup);
    } finally {
        DB::rollBack();
        DB::disableQueryLog();
    }
});

it('invalidates map links when a published landing page URL changes', function (): void {
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create());
    $landingPage = LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
        'external_path' => 'old-path',
    ]));
    Cache::flush();
    app()->forgetInstance(PortalCacheInvalidationService::class);

    $versions = app(PortalCacheVersionService::class);
    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1)
        ->and($versions->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::DOI))->toBe(1)
        ->and($versions->current(CacheKey::PORTAL_MAP_EXTENT, PortalScope::DOI))->toBe(1);

    $landingPage->update(['external_path' => 'new-path']);

    expect($versions->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(2)
        ->and($versions->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::DOI))->toBe(2)
        ->and($versions->current(CacheKey::PORTAL_MAP_EXTENT, PortalScope::DOI))->toBe(1);
});
