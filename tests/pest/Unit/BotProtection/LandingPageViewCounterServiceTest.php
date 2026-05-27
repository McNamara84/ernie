<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\LandingPage;
use App\Models\LandingPageDailyStatistic;
use App\Models\Resource;
use App\Services\BotProtection\BotClassifierService;
use App\Services\BotProtection\LandingPageViewCounterService;
use App\Services\Statistics\LandingPageAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

covers(LandingPageViewCounterService::class);

beforeEach(function (): void {
    Cache::flush();

    config([
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot'],
        'bot_protection.view_count_debounce_seconds' => 3600,
    ]);
});

function botProtectionViewCounterLandingPage(): LandingPage
{
    $resource = Resource::factory()->create();

    return LandingPage::factory()
        ->published()
        ->create([
            'resource_id' => $resource->id,
            'view_count' => 0,
        ]);
}

function botProtectionViewCounterRequest(string $ipAddress = '203.0.113.10', string $userAgent = 'Mozilla/5.0'): Request
{
    return Request::create('/10.5880/test/resource', 'GET', server: [
        'REMOTE_ADDR' => $ipAddress,
        'HTTP_USER_AGENT' => $userAgent,
    ]);
}

function botProtectionViewCounterDailyViewCount(LandingPage $landingPage): ?int
{
    return LandingPageDailyStatistic::query()
        ->where('landing_page_id', $landingPage->id)
        ->whereDate('statistic_date', now()->toDateString())
        ->value('page_view_count');
}

function botProtectionViewCounterService(): LandingPageViewCounterService
{
    return new LandingPageViewCounterService(new BotClassifierService, new LandingPageAnalyticsService);
}

it('increments every request when bot protection is disabled', function (): void {
    config(['bot_protection.enabled' => false]);

    $service = botProtectionViewCounterService();
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2)
        ->and(botProtectionViewCounterDailyViewCount($landingPage))->toBe(2);
});

it('skips known ai bot requests when bot protection is enabled', function (): void {
    $service = botProtectionViewCounterService();
    $landingPage = botProtectionViewCounterLandingPage();

    $service->record(botProtectionViewCounterRequest(userAgent: 'GPTBot'), $landingPage);

    expect($landingPage->fresh()->view_count)->toBe(0)
        ->and(botProtectionViewCounterDailyViewCount($landingPage))->toBeNull();
});

it('increments every request when the debounce window is disabled', function (): void {
    config(['bot_protection.view_count_debounce_seconds' => 0]);

    $service = botProtectionViewCounterService();
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2)
        ->and(botProtectionViewCounterDailyViewCount($landingPage))->toBe(2);
});

it('debounces repeated requests from the same visitor fingerprint', function (): void {
    $service = botProtectionViewCounterService();
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());
    $service->record(botProtectionViewCounterRequest(ipAddress: '203.0.113.11'), $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2)
        ->and(botProtectionViewCounterDailyViewCount($landingPage))->toBe(2);
});

it('keeps public caches warm when recording a human view', function (): void {
    $service = botProtectionViewCounterService();
    $landingPage = botProtectionViewCounterLandingPage();
    $renderCacheKey = CacheKey::LANDING_PAGE_RENDER_DATA->key($landingPage->id);
    $portalCacheKey = CacheKey::PORTAL_PAGE_PAYLOAD->key('page:filters');

    Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->put($renderCacheKey, ['template' => 'default_gfz', 'props' => []], 600);
    Cache::tags(CacheKey::PORTAL_PAGE_PAYLOAD->tags())->put($portalCacheKey, ['props' => []], 600);

    $service->record(botProtectionViewCounterRequest(), $landingPage);

    expect($landingPage->fresh()->view_count)->toBe(1)
        ->and(botProtectionViewCounterDailyViewCount($landingPage))->toBe(1)
        ->and(Cache::tags(CacheKey::LANDING_PAGE_RENDER_DATA->tags())->has($renderCacheKey))->toBeTrue()
        ->and(Cache::tags(CacheKey::PORTAL_PAGE_PAYLOAD->tags())->has($portalCacheKey))->toBeTrue();
});