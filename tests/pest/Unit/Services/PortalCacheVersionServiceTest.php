<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Services\PortalCacheVersionService;
use App\Support\PortalCacheNamespace;
use Illuminate\Support\Facades\Cache;

covers(PortalCacheVersionService::class, PortalCacheNamespace::class);

beforeEach(function (): void {
    Cache::flush();
});

it('keeps versions separate by cache area and portal scope', function (): void {
    $service = app(PortalCacheVersionService::class);

    expect($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(1)
        ->and($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::IGSN))->toBe(1)
        ->and($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, null))->toBe(1)
        ->and($service->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::DOI))->toBe(1);

    expect($service->invalidate(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(2)
        ->and($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::DOI))->toBe(2)
        ->and($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, PortalScope::IGSN))->toBe(1)
        ->and($service->current(CacheKey::PORTAL_PAGE_PAYLOAD, null))->toBe(1)
        ->and($service->current(CacheKey::PORTAL_MAP_PAYLOAD, PortalScope::DOI))->toBe(1);
});

it('builds deterministic scoped namespaces', function (): void {
    expect(PortalCacheNamespace::tags(CacheKey::PORTAL_MAP_EXTENT, PortalScope::IGSN))->toBe([
        'portal-cache:v2:portal:map_extent:igsn',
    ])->and(PortalCacheNamespace::versionKey(CacheKey::PORTAL_MAP_EXTENT, PortalScope::IGSN))
        ->toBe('portal-cache-version:v2:portal:map_extent:igsn')
        ->and(PortalCacheNamespace::versionedKey(
            CacheKey::PORTAL_MAP_EXTENT,
            PortalScope::IGSN,
            3,
            'filters',
        ))->toBe('portal:map_extent:igsn:v3:filters');

    expect(PortalCacheNamespace::versionedKey(
        CacheKey::PORTAL_TEMPORAL_RANGE,
        null,
        2,
    ))->toBe('portal:temporal_range:all:v2');
});
