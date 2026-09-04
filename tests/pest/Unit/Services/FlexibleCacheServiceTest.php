<?php

declare(strict_types=1);

use App\Services\FlexibleCacheService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;

covers(FlexibleCacheService::class);

beforeEach(function (): void {
    Cache::flush();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('resolves a cold miss once and reuses the fresh value', function (): void {
    $calls = 0;
    $service = app(FlexibleCacheService::class);
    $repository = Cache::store();

    $first = $service->remember($repository, 'portal:test', 30, 60, function () use (&$calls): string {
        $calls++;

        return 'payload';
    }, 5, 1);
    $second = $service->remember($repository, 'portal:test', 30, 60, function () use (&$calls): string {
        $calls++;

        return 'replacement';
    }, 5, 1);

    expect($first)->toBe('payload')
        ->and($second)->toBe('payload')
        ->and($calls)->toBe(1);
});

it('bypasses the cache when the stale ttl is disabled', function (): void {
    $calls = 0;
    $service = app(FlexibleCacheService::class);

    $resolver = function () use (&$calls): int {
        return ++$calls;
    };

    expect($service->remember(Cache::store(), 'portal:disabled', 0, 0, $resolver, 5, 1))->toBe(1)
        ->and($service->remember(Cache::store(), 'portal:disabled', 0, 0, $resolver, 5, 1))->toBe(2);
});

it('returns a stale value immediately and refreshes it after the response', function (): void {
    $now = Carbon::parse('2026-09-04 12:00:00');
    Carbon::setTestNow($now);

    $calls = 0;
    $service = app(FlexibleCacheService::class);
    $repository = Cache::store();
    $resolver = function () use (&$calls): string {
        $calls++;

        return "payload-{$calls}";
    };

    expect($service->remember($repository, 'portal:stale', 30, 60, $resolver, 5, 1))->toBe('payload-1');

    Carbon::setTestNow($now->copy()->addSeconds(31));

    expect($service->remember($repository, 'portal:stale', 30, 60, $resolver, 5, 1))->toBe('payload-1')
        ->and($calls)->toBe(1);

    app(DeferredCallbackCollection::class)->invoke();

    expect($repository->get('portal:stale'))->toBe('payload-2')
        ->and($calls)->toBe(2);
});

it('keeps the stale value when its deferred refresh fails', function (): void {
    $now = Carbon::parse('2026-09-04 12:00:00');
    Carbon::setTestNow($now);

    $service = app(FlexibleCacheService::class);
    $repository = Cache::store();

    expect($service->remember(
        $repository,
        'portal:failed-refresh',
        30,
        60,
        static fn (): string => 'last-known-good',
        5,
        1,
    ))->toBe('last-known-good');

    Carbon::setTestNow($now->copy()->addSeconds(31));

    expect($service->remember(
        $repository,
        'portal:failed-refresh',
        30,
        60,
        static fn (): never => throw new RuntimeException('refresh failed'),
        5,
        1,
    ))->toBe('last-known-good');

    app(DeferredCallbackCollection::class)->invoke();

    expect($repository->get('portal:failed-refresh'))->toBe('last-known-good');
});

it('does not duplicate a cold calculation when its lock cannot be acquired', function (): void {
    $repository = Cache::store();
    $lockKey = 'flexible-cache:cold:'.hash('sha256', $repository->getStore()::class.'|portal:locked');
    $lock = Cache::lock($lockKey, 5);
    $lock->get();

    try {
        expect(fn () => app(FlexibleCacheService::class)->remember(
            $repository,
            'portal:locked',
            30,
            60,
            static fn (): string => 'must not run',
            5,
            1,
        ))->toThrow(LockTimeoutException::class);
    } finally {
        $lock->release();
    }
});
