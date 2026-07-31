<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Enums\CacheKey;
use App\Models\Format;
use App\Models\LandingPage;
use App\Models\LandingPageFile;
use App\Models\LandingPageLink;
use App\Models\Resource;
use App\Models\Size;
use App\Observers\FormatObserver;
use App\Observers\LandingPageFileObserver;
use App\Observers\LandingPageLinkObserver;
use App\Observers\ResourceObserver;
use App\Observers\SizeObserver;
use Illuminate\Support\Facades\Cache;

covers(FormatObserver::class, SizeObserver::class, ResourceObserver::class, LandingPageFileObserver::class, LandingPageLinkObserver::class);

function putLandingPageRenderCache(LandingPage $landingPage): void
{
    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put(
        CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id),
        ['template' => 'default_gfz', 'props' => [], 'viewData' => []],
        600,
    );
}

function expectLandingPageRenderCacheMissing(LandingPage $landingPage): void
{
    expect(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has(
        CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id),
    ))->toBeFalse();
}

test('format create update and delete invalidate the landing page render cache', function () {
    $resource = Resource::factory()->create();
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    putLandingPageRenderCache($landingPage);
    $format = Format::create(['resource_id' => $resource->id, 'value' => 'application/zip']);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $format->update(['value' => 'application/pdf']);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $format->delete();
    expectLandingPageRenderCacheMissing($landingPage);
});

test('size create update and delete invalidate the landing page render cache', function () {
    $resource = Resource::factory()->create();
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    putLandingPageRenderCache($landingPage);
    $size = Size::create(['resource_id' => $resource->id, 'numeric_value' => 2, 'unit' => 'MB']);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $size->update(['numeric_value' => 3]);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $size->delete();
    expectLandingPageRenderCacheMissing($landingPage);
});

test('access level changes invalidate the landing page render cache', function () {
    $resource = Resource::factory()->create(['access_level' => AccessLevel::OPEN]);
    $landingPage = LandingPage::factory()->published()->create(['resource_id' => $resource->id]);

    putLandingPageRenderCache($landingPage);
    $resource->update(['access_level' => AccessLevel::RESTRICTED]);

    expectLandingPageRenderCacheMissing($landingPage);
});

test('landing page file create update and delete invalidate the render cache', function () {
    $landingPage = LandingPage::factory()->published()->create();

    putLandingPageRenderCache($landingPage);
    $file = LandingPageFile::create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://downloads.example.org/data.zip',
        'position' => 0,
    ]);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $file->update(['url' => 'https://downloads.example.org/data-v2.zip']);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $file->delete();
    expectLandingPageRenderCacheMissing($landingPage);
});

test('landing page link create update and delete invalidate the render cache', function () {
    $landingPage = LandingPage::factory()->published()->create();

    putLandingPageRenderCache($landingPage);
    $link = LandingPageLink::create([
        'landing_page_id' => $landingPage->id,
        'url' => 'https://example.org/project',
        'label' => 'Project',
        'kind' => LandingPageLink::KIND_RELATED,
        'position' => 0,
    ]);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $link->update(['kind' => LandingPageLink::KIND_REPOSITORY]);
    expectLandingPageRenderCacheMissing($landingPage);

    putLandingPageRenderCache($landingPage);
    $link->delete();
    expectLandingPageRenderCacheMissing($landingPage);
});
