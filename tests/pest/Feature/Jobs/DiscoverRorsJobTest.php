<?php

declare(strict_types=1);

use App\Jobs\DiscoverRorsJob;
use App\Services\RorDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(DiscoverRorsJob::class);

beforeEach(function (): void {
    Cache::flush();
});

// =========================================================================
// Constructor validation
// =========================================================================

describe('constructor validation', function () {
    it('accepts a valid UUID', function () {
        $job = new DiscoverRorsJob(Str::uuid()->toString());
        expect($job)->toBeInstanceOf(DiscoverRorsJob::class);
    });

    it('rejects invalid UUID format')
        ->throws(InvalidArgumentException::class, 'Job ID must be a valid UUID')
        ->expect(fn () => new DiscoverRorsJob('not-a-uuid'));
});

// =========================================================================
// getCacheKey()
// =========================================================================

describe('getCacheKey', function () {
    it('returns formatted cache key', function () {
        $uuid = Str::uuid()->toString();
        expect(DiscoverRorsJob::getCacheKey($uuid))->toBe("ror_discovery:{$uuid}");
    });
});

// =========================================================================
// handle()
// =========================================================================

describe('handle', function () {
    it('sets cache status to running then completed', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) use ($cacheKey) {
                // While running, cache should show running status
                $cached = Cache::get($cacheKey);
                expect($cached)->toBeArray()
                    ->and($cached['status'])->toBe('running');

                // Simulate progress callback
                if ($callback !== null) {
                    $callback(1, 3);
                }

                return 5;
            });

        $job = new DiscoverRorsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('completed')
            ->and($cached['newRorsFound'])->toBe(5)
            ->and($cached['startedAt'])->toBeString()
            ->and($cached['completedAt'])->toBeString();
    });

    it('updates progress via callback', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) use ($cacheKey) {
                $callback(2, 5);

                $cached = Cache::get($cacheKey);
                expect($cached['status'])->toBe('running')
                    ->and($cached['processedEntities'])->toBe(2)
                    ->and($cached['totalEntities'])->toBe(5)
                    ->and($cached['progress'])->toBe('Checking entity 2 of 5...');

                return 0;
            });

        $job = new DiscoverRorsJob($uuid);
        $job->handle($service);
    });

    it('re-throws exception without overwriting cache status', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andThrow(new RuntimeException('ROR API connection failed'));

        $job = new DiscoverRorsJob($uuid);

        expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'ROR API connection failed');

        // Cache should still be in 'running' state — the failed() callback
        // (triggered by the queue worker) is responsible for setting 'failed'
        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('running')
            ->and($cached['startedAt'])->toBeString();
    });

    it('tracks totalEntities correctly in completed status', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) {
                $callback(1, 10);
                $callback(2, 10);
                $callback(10, 10);

                return 3;
            });

        $job = new DiscoverRorsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached['totalEntities'])->toBe(10)
            ->and($cached['processedEntities'])->toBe(10);
    });

    it('releases lock after successful completion', function () {
        $uuid = Str::uuid()->toString();

        Cache::lock('ror_discovery_running', 7200)->forceRelease();
        $lock = Cache::lock('ror_discovery_running', 7200);
        $lock->acquire();

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturn(0);

        $job = new DiscoverRorsJob($uuid, $lock->owner());
        $job->handle($service);

        // Lock should be released — a new lock should be acquirable
        $newLock = Cache::lock('ror_discovery_running', 7200);
        expect($newLock->get())->toBeTrue();
        $newLock->release();
    });

    it('releases lock after exception', function () {
        $uuid = Str::uuid()->toString();

        Cache::lock('ror_discovery_running', 7200)->forceRelease();
        $lock = Cache::lock('ror_discovery_running', 7200);
        $lock->acquire();

        $service = Mockery::mock(RorDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andThrow(new RuntimeException('Fail'));

        $job = new DiscoverRorsJob($uuid, $lock->owner());

        try {
            $job->handle($service);
        } catch (RuntimeException) {
            // expected
        }

        // Lock should be released
        $newLock = Cache::lock('ror_discovery_running', 7200);
        expect($newLock->get())->toBeTrue();
        $newLock->release();
    });
});

// =========================================================================
// failed()
// =========================================================================

describe('failed', function () {
    it('sets cache status to failed with error message', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $job = new DiscoverRorsJob($uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout');
    });

    it('handles null exception', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        $job = new DiscoverRorsJob($uuid);
        $job->failed(null);

        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Unknown error');
    });

    it('preserves running state data when merging failure info', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRorsJob::getCacheKey($uuid);

        // Simulate a running state left by handle()
        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Checking entity 3 of 10...',
            'totalEntities' => 10,
            'processedEntities' => 3,
            'newRorsFound' => 0,
            'startedAt' => '2026-03-29T00:00:00+00:00',
        ], now()->addHours(2));

        $job = new DiscoverRorsJob($uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout')
            ->and($cached['startedAt'])->toBe('2026-03-29T00:00:00+00:00')
            ->and($cached['totalEntities'])->toBe(10)
            ->and($cached['processedEntities'])->toBe(3)
            ->and($cached['completedAt'])->toBeString();
    });
});
