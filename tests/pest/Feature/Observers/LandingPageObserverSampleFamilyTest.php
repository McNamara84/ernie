<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\Resource;
use Illuminate\Support\Facades\Cache;

function issue1127PutRenderCache(LandingPage $landingPage): void
{
    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put(
        CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id),
        ['template' => 'default_gfz_igsn', 'props' => []],
        600,
    );
}

it('invalidates family render caches when an IGSN landing page is created, updated, or deleted', function (): void {
    $rootResource = Resource::factory()->create([
        'doi' => '10.60510/landing-observer-root',
        'identifier_type' => 'IGSN',
    ]);
    $childResource = Resource::factory()->create([
        'doi' => '10.60510/landing-observer-child',
        'identifier_type' => 'IGSN',
    ]);
    IgsnMetadata::query()->create([
        'resource_id' => $rootResource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);
    IgsnMetadata::query()->create([
        'resource_id' => $childResource->id,
        'parent_resource_id' => $rootResource->id,
        'upload_status' => IgsnMetadata::STATUS_REGISTERED,
    ]);

    $rootLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => $rootResource->id,
    ]);
    issue1127PutRenderCache($rootLandingPage);

    $childLandingPage = LandingPage::factory()->published()->create([
        'resource_id' => $childResource->id,
        'slug' => 'landing-observer-child',
    ]);

    $cache = Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags());
    expect($cache->has(CacheKey::LANDING_PAGE_RENDER_DATA->key($rootLandingPage->id)))->toBeFalse();

    issue1127PutRenderCache($rootLandingPage);
    issue1127PutRenderCache($childLandingPage);
    $childLandingPage->update(['ftp_url' => 'https://example.test/sample-files']);

    expect($cache->has(CacheKey::LANDING_PAGE_RENDER_DATA->key($rootLandingPage->id)))->toBeFalse()
        ->and($cache->has(CacheKey::LANDING_PAGE_RENDER_DATA->key($childLandingPage->id)))->toBeFalse();

    issue1127PutRenderCache($rootLandingPage);
    issue1127PutRenderCache($childLandingPage);
    $childLandingPage->delete();

    expect($cache->has(CacheKey::LANDING_PAGE_RENDER_DATA->key($rootLandingPage->id)))->toBeFalse()
        ->and($cache->has(CacheKey::LANDING_PAGE_RENDER_DATA->key($childLandingPage->id)))->toBeFalse();
});
