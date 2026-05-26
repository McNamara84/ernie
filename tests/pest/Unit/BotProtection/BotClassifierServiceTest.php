<?php

declare(strict_types=1);

use App\Services\BotProtection\BotClassifierService;
use Illuminate\Http\Request;

covers(BotClassifierService::class);

beforeEach(function (): void {
    config([
        'bot_protection.enabled' => true,
        'bot_protection.ai_user_agents' => ['GPTBot', 'ClaudeBot'],
    ]);
});

it('detects configured ai bot user agents case insensitively', function (): void {
    $service = new BotClassifierService;

    expect($service->isKnownAiBot('Mozilla/5.0 compatible; gptbot/1.2'))->toBeTrue()
        ->and($service->isKnownAiBot('ClaudeBot'))->toBeTrue();
});

it('does not classify normal or empty user agents as ai bots', function (): void {
    $service = new BotClassifierService;

    expect($service->isKnownAiBot('Mozilla/5.0'))->toBeFalse()
        ->and($service->isKnownAiBot(''))->toBeFalse()
        ->and($service->isKnownAiBot(null))->toBeFalse();
});

it('does not classify ai bots when bot protection is disabled', function (): void {
    config(['bot_protection.enabled' => false]);

    expect((new BotClassifierService)->isKnownAiBot('GPTBot'))->toBeFalse();
});

it('ignores malformed ai user agent configuration values', function (): void {
    config(['bot_protection.ai_user_agents' => 'GPTBot']);

    expect((new BotClassifierService)->isKnownAiBot('GPTBot'))->toBeFalse();
});

it('builds rate limit keys from surface classification and ip address', function (): void {
    $request = Request::create('/portal', 'GET', server: [
        'REMOTE_ADDR' => '192.0.2.10',
        'HTTP_USER_AGENT' => 'GPTBot',
    ]);

    expect((new BotClassifierService)->rateLimitKey($request, 'portal'))->toBe('portal:ai-bot:192.0.2.10');
});

it('builds public rate limit keys for normal visitors', function (): void {
    $request = Request::create('/portal', 'GET', server: [
        'REMOTE_ADDR' => '192.0.2.11',
        'HTTP_USER_AGENT' => 'Mozilla/5.0',
    ]);

    expect((new BotClassifierService)->rateLimitKey($request, 'portal'))->toBe('portal:public:192.0.2.11');
});
