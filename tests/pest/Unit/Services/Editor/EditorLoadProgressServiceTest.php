<?php

declare(strict_types=1);

use App\Enums\EditorLoadStage;
use App\Services\Editor\EditorLoadProgressService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

beforeEach(function (): void {
    config()->set('cache.default', 'array');
    Cache::flush();
    Carbon::setTestNow('2026-08-19 10:00:00');
});

afterEach(function (): void {
    Carbon::setTestNow();
    Cache::flush();
});

it('binds a new load to one user and resource', function (): void {
    $tracker = app(EditorLoadProgressService::class);
    $state = $tracker->begin(7, 42);
    $token = (string) $state['token'];

    expect($tracker->findForUser($token, 7, 42))
        ->not->toBeNull()
        ->and($tracker->findForUser($token, 8, 42))->toBeNull()
        ->and($tracker->findForUser($token, 7, 43))->toBeNull()
        ->and($tracker->findForUser('not-a-uuid', 7, 42))->toBeNull()
        ->and($state['status'])->toBe('pending')
        ->and($state['progress'])->toBe(0);
});

it('advances monotonically through real server phases', function (): void {
    $tracker = app(EditorLoadProgressService::class);
    $state = $tracker->begin(7, 42);
    $token = (string) $state['token'];

    $loaded = $tracker->advance($token, 7, 42, EditorLoadStage::PEOPLE_RELATIONS_LOADED);
    $regression = $tracker->advance($token, 7, 42, EditorLoadStage::RESOURCE_LOADED);
    $ready = $tracker->advance($token, 7, 42, EditorLoadStage::SERVER_READY);

    expect($loaded['progress'] ?? null)->toBe(40)
        ->and($regression['progress'] ?? null)->toBe(40)
        ->and($ready['progress'] ?? null)->toBe(75)
        ->and($ready['status'] ?? null)->toBe('server_ready')
        ->and($ready['stage'] ?? null)->toBe(EditorLoadStage::SERVER_READY->value);
});

it('logs a slow load at twelve seconds exactly once', function (): void {
    Log::spy();

    $tracker = app(EditorLoadProgressService::class);
    $state = $tracker->begin(7, 42);
    $token = (string) $state['token'];

    Carbon::setTestNow('2026-08-19 10:00:11.999');
    expect($tracker->logIfSlow($token, 7, 42, 'loader', 55, 'client'))->toBeFalse();

    Carbon::setTestNow('2026-08-19 10:00:12');
    expect($tracker->logIfSlow($token, 7, 42, 'client_vocabularies', 90, 'client'))->toBeTrue()
        ->and($tracker->logIfSlow($token, 7, 42, 'client_ready', 100, 'client'))->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with('Slow Data Editor resource load', Mockery::on(fn (array $context): bool => $context === [
            'user_id' => 7,
            'resource_id' => 42,
            'duration_ms' => 12_000,
            'stage' => 'client_vocabularies',
            'progress' => 90,
            'source' => 'client',
        ]));
});

it('keeps the latest phase when a load fails', function (): void {
    $tracker = app(EditorLoadProgressService::class);
    $state = $tracker->begin(7, 42);
    $token = (string) $state['token'];

    $tracker->advance($token, 7, 42, EditorLoadStage::CONTENT_RELATIONS_LOADED);
    $tracker->fail($token, 7, 42, 'Unable to load the resource.');

    $failed = $tracker->findForUser($token, 7, 42);

    expect($failed['status'] ?? null)->toBe('failed')
        ->and($failed['stage'] ?? null)->toBe(EditorLoadStage::CONTENT_RELATIONS_LOADED->value)
        ->and($failed['progress'] ?? null)->toBe(25)
        ->and($failed['error'] ?? null)->toBe('Unable to load the resource.');
});
