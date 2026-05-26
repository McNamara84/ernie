<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Services\BotProtection\BotClassifierService;
use App\Services\BotProtection\LandingPageViewCounterService;
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

it('increments every request when bot protection is disabled', function (): void {
    config(['bot_protection.enabled' => false]);

    $service = new LandingPageViewCounterService(new BotClassifierService);
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2);
});

it('skips known ai bot requests when bot protection is enabled', function (): void {
    $service = new LandingPageViewCounterService(new BotClassifierService);
    $landingPage = botProtectionViewCounterLandingPage();

    $service->record(botProtectionViewCounterRequest(userAgent: 'GPTBot'), $landingPage);

    expect($landingPage->fresh()->view_count)->toBe(0);
});

it('increments every request when the debounce window is disabled', function (): void {
    config(['bot_protection.view_count_debounce_seconds' => 0]);

    $service = new LandingPageViewCounterService(new BotClassifierService);
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2);
});

it('debounces repeated requests from the same visitor fingerprint', function (): void {
    $service = new LandingPageViewCounterService(new BotClassifierService);
    $landingPage = botProtectionViewCounterLandingPage();
    $request = botProtectionViewCounterRequest();

    $service->record($request, $landingPage);
    $service->record($request, $landingPage->fresh());
    $service->record(botProtectionViewCounterRequest(ipAddress: '203.0.113.11'), $landingPage->fresh());

    expect($landingPage->fresh()->view_count)->toBe(2);
});