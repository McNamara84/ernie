<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
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

it('ignores an unversioned legacy entry and leaves it untouched', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $legacyCacheKey = "landing_pages:render_data:{$landingPage->id}";
    $versionedCacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $legacyPayload = ['template' => 'default_gfz', 'props' => ['legacy' => true]];
    $calls = 0;
    $resolver = function () use (&$calls): array {
        $calls++;

        return ['template' => 'default_gfz', 'props' => ['calls' => $calls]];
    };
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());

    $cache->put($legacyCacheKey, $legacyPayload, 600);

    $first = $service->remember($landingPage, $resolver);
    $second = $service->remember($landingPage, $resolver);

    expect($versionedCacheKey)->not->toBe($legacyCacheKey)
        ->and($first)->toBe(['template' => 'default_gfz', 'props' => ['calls' => 1]])
        ->and($second)->toBe($first)
        ->and($calls)->toBe(1)
        ->and($cache->get($legacyCacheKey))->toBe($legacyPayload)
        ->and($cache->has($versionedCacheKey))->toBeTrue();
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

it('forgets versioned render data by id without deleting a legacy entry', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $landingPage = botProtectionRenderCacheLandingPage();
    $legacyCacheKey = "landing_pages:render_data:{$landingPage->id}";
    $versionedCacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());

    $cache->put($legacyCacheKey, ['template' => 'default_gfz', 'props' => ['legacy' => true]], 600);
    $cache->put($versionedCacheKey, ['template' => 'default_gfz', 'props' => []], 600);

    expect($service->forgetById($landingPage->id))->toBeTrue()
        ->and($cache->has($versionedCacheKey))->toBeFalse()
        ->and($cache->has($legacyCacheKey))->toBeTrue();
});

it('forgets cached render data for landing pages using a custom template without clearing same-tag schema cache', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $template = LandingPageTemplate::factory()->create();
    $otherTemplate = LandingPageTemplate::factory()->create();
    $affectedLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => $template->id,
    ]);
    $unaffectedLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => $otherTemplate->id,
    ]);

    $affectedRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($affectedLandingPage->id);
    $unaffectedRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($unaffectedLandingPage->id);
    $schemaOrgKey = CacheKey::SCHEMA_ORG_JSONLD->key($affectedLandingPage->resource_id);

    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($affectedRenderKey, ['template' => 'default_gfz', 'props' => []], 600);
    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($unaffectedRenderKey, ['template' => 'default_gfz', 'props' => []], 600);
    Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->put($schemaOrgKey, ['@context' => 'https://schema.org'], 600);

    expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($affectedRenderKey))->toBeTrue()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($unaffectedRenderKey))->toBeTrue()
        ->and(Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->has($schemaOrgKey))->toBeTrue();

    $service->forgetForTemplate($template);

    expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($affectedRenderKey))->toBeFalse()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($unaffectedRenderKey))->toBeTrue()
        ->and(Cache::tags(CacheKey::SCHEMA_ORG_JSONLD->tags())->has($schemaOrgKey))->toBeTrue();
});

it('forgets cached render data for default-template pages without clearing matching custom-template pages', function (): void {
    $service = new LandingPageRenderDataCacheService;
    $defaultTemplate = LandingPageTemplate::ensureDefaultTemplateExists();
    $customTemplate = LandingPageTemplate::factory()->create();
    $mismatchedTemplate = LandingPageTemplate::factory()->igsn()->create();
    $nullTemplateLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => null,
    ]);
    $defaultIdLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => $defaultTemplate->id,
    ]);
    $mismatchedTemplateLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => $mismatchedTemplate->id,
    ]);
    $customTemplateLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => Resource::factory()->create()->id,
        'landing_page_template_id' => $customTemplate->id,
    ]);

    $nullTemplateRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($nullTemplateLandingPage->id);
    $defaultIdRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($defaultIdLandingPage->id);
    $mismatchedTemplateRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($mismatchedTemplateLandingPage->id);
    $customTemplateRenderKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($customTemplateLandingPage->id);

    foreach ([$nullTemplateRenderKey, $defaultIdRenderKey, $mismatchedTemplateRenderKey, $customTemplateRenderKey] as $renderKey) {
        Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($renderKey, ['template' => 'default_gfz', 'props' => []], 600);
    }

    $service->forgetForTemplate($defaultTemplate);

    expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($nullTemplateRenderKey))->toBeFalse()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($defaultIdRenderKey))->toBeFalse()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($mismatchedTemplateRenderKey))->toBeFalse()
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($customTemplateRenderKey))->toBeTrue();
});
