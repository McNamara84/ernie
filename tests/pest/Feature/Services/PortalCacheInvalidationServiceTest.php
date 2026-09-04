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
