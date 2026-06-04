<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use Illuminate\Support\Facades\Cache;

covers(LandingPageRenderDataCacheService::class);

beforeEach(function (): void {
    Cache::flush();

    config([
        'bot_protection.enabled' => true,
        'bot_protection.landing_cache_ttl' => 600,
    ]);
});

function botProtectionRenderCacheLandingPage(bool $published = true): LandingPage
{
    $resource = Resource::factory()->create();

    return LandingPage::factory()->create([
        'resource_id' => $resource->id,
        'is_published' => $published,
        'published_at' => $published ? now() : null,
    ]);
}

it('caches published landing page render data', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['template' => 'default_gfz', 'props' => ['calls' => $calls]];
    };

    $first = $service->remember($landingPage, $resolver);
    $second = $service->remember($landingPage, $resolver);

    expect($first)->toBe(['template' => 'default_gfz', 'props' => ['calls' => 1]])
        ->and($second)->toBe($first)
        ->and($calls)->toBe(1);
});

it('does not cache draft landing page render data', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage(published: false);
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['template' => 'default_gfz', 'props' => ['calls' => $calls]];
    };

    $service->remember($landingPage, $resolver);
    $second = $service->remember($landingPage, $resolver);

    expect($second)->toBe(['template' => 'default_gfz', 'props' => ['calls' => 2]])
        ->and($calls)->toBe(2);
});

it('does not cache when bot protection is disabled', function (): void {
    config(['bot_protection.enabled' => false]);

    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['template' => 'default_gfz', 'props' => ['calls' => $calls]];
    };

    $service->remember($landingPage, $resolver);
    $service->remember($landingPage, $resolver);

    expect($calls)->toBe(2);
});

it('does not cache when the landing cache ttl is zero', function (): void {
    config(['bot_protection.landing_cache_ttl' => 0]);

    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['template' => 'default_gfz', 'props' => ['calls' => $calls]];
    };

    $service->remember($landingPage, $resolver);
    $service->remember($landingPage, $resolver);

    expect($calls)->toBe(2);
});

it('forgets cached render data through the tagged cache repository', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);

    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($cacheKey, ['template' => 'default_gfz', 'props' => []], 600);

    expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeTrue()
        ->and($service->forget($landingPage))->toBeTrue()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($cacheKey))->toBeFalse();
});

it('flushes all tagged landing page render data without clearing unrelated tags', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $cacheKey = CacheKey::LANDING_PAGE_RENDER_DATA;

    Cache::tags($cacheKey->tags())->put($cacheKey->key(1), ['template' => 'default_gfz', 'props' => []], 600);
    Cache::tags($cacheKey->tags())->put($cacheKey->key(2), ['template' => 'default_gfz', 'props' => []], 600);
    Cache::tags(['portal'])->put('portal-payload', ['props' => []], 600);

    expect(Cache::tags($cacheKey->tags())->has($cacheKey->key(1)))->toBeTrue()
        ->and(Cache::tags($cacheKey->tags())->has($cacheKey->key(2)))->toBeTrue()
        ->and(Cache::tags(['portal'])->has('portal-payload'))->toBeTrue();

    $service->flush();

    expect(Cache::tags($cacheKey->tags())->has($cacheKey->key(1)))->toBeFalse()
        ->and(Cache::tags($cacheKey->tags())->has($cacheKey->key(2)))->toBeFalse()
        ->and(Cache::tags(['portal'])->has('portal-payload'))->toBeTrue();
});

it('falls back to flushing the whole cache store when tags are unsupported', function (): void {
    Cache::shouldReceive('getStore')
        ->once()
        ->andReturn(new class {});
    Cache::shouldReceive('flush')
        ->once();

    (new LandingPageRenderDataCacheService)->flush();
});
