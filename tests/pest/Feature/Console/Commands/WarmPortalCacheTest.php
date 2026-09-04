<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\PortalScope;
use App\Services\BotProtection\PortalMapCacheService;
use App\Services\BotProtection\PortalPageCacheService;
use App\Services\ListingCountService;
use App\Services\PortalCacheVersionService;
use App\Services\PortalFilterService;
use App\Support\PortalCacheNamespace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config([
        'bot_protection.enabled' => true,
        'bot_protection.portal_cache_ttl' => 120,
        'bot_protection.portal_cache_fresh_ttl' => 60,
    ]);
});

it('warms the default page for both public portal scopes idempotently', function (): void {
    $this->artisan('portal:cache-warm', ['--area' => ['page']])
        ->expectsOutputToContain('Warmed doi portal cache.')
        ->expectsOutputToContain('Warmed igsn portal cache.')
        ->assertSuccessful();

    $pageCache = app(PortalPageCacheService::class);

    foreach (PortalScope::cases() as $scope) {
        $request = Request::create($scope->basePath(), 'GET');
        $repository = method_exists(Cache::getStore(), 'tags')
            ? Cache::tags(PortalCacheNamespace::tags(CacheKey::PORTAL_PAGE_PAYLOAD, $scope))
            : Cache::store();
        expect($repository->has($pageCache->keyForRequest($request, $scope)))->toBeTrue();
    }

    $this->artisan('portal:cache-warm', ['--area' => ['page']])->assertSuccessful();
});

it('warms count, facet and map extent caches for both portal scopes', function (): void {
    $this->artisan('portal:cache-warm')->assertSuccessful();

    $filterService = app(PortalFilterService::class);
    $countService = app(ListingCountService::class);
    $versionService = app(PortalCacheVersionService::class);
    $mapCache = app(PortalMapCacheService::class);

    foreach (PortalScope::cases() as $scope) {
        foreach ([CacheKey::PORTAL_TEMPORAL_RANGE, CacheKey::PORTAL_DATACENTER_FACETS] as $cacheKey) {
            $repository = method_exists(Cache::getStore(), 'tags')
                ? Cache::tags($cacheKey->tags())
                : Cache::store();
            expect($repository->has($cacheKey->key($scope->value)))->toBeTrue();
        }

        $filters = $filterService->fromRequest(
            Request::create($scope->basePath(), 'GET'),
            [],
            $scope,
        );
        $countCriteria = [
            ...$filters,
            '_portal_cache_version' => $versionService->current(CacheKey::PORTAL_LISTING_COUNT, $scope),
        ];
        $countRepository = method_exists(Cache::getStore(), 'tags')
            ? Cache::tags(CacheKey::PORTAL_LISTING_COUNT->tags())
            : Cache::store();
        expect($countRepository->has(CacheKey::PORTAL_LISTING_COUNT->key($countService->fingerprint($countCriteria))))->toBeTrue();

        $mapRepository = method_exists(Cache::getStore(), 'tags')
            ? Cache::tags(PortalCacheNamespace::tags(CacheKey::PORTAL_MAP_EXTENT, $scope))
            : Cache::store();
        expect($mapRepository->has($mapCache->extentKeyForFilters($filters, $scope)))->toBeTrue();
    }
});

it('rejects invalid scope and area options', function (): void {
    $this->artisan('portal:cache-warm', ['--scope' => 'unknown'])->assertFailed();
    $this->artisan('portal:cache-warm', ['--area' => ['unknown']])->assertFailed();
});
