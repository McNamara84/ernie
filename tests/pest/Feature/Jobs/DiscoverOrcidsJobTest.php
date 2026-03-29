<?php

declare(strict_types=1);

use App\Jobs\DiscoverOrcidsJob;
use App\Services\OrcidDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(DiscoverOrcidsJob::class);

beforeEach(function (): void {
    Cache::flush();
});

// =========================================================================
// Constructor validation
// =========================================================================

describe('constructor validation', function () {
    it('accepts a valid UUID', function () {
        $job = new DiscoverOrcidsJob(Str::uuid()->toString());
        expect($job)->toBeInstanceOf(DiscoverOrcidsJob::class);
    });

    it('rejects invalid UUID format')
        ->throws(InvalidArgumentException::class, 'Job ID must be a valid UUID')
        ->expect(fn () => new DiscoverOrcidsJob('not-a-uuid'));
});

// =========================================================================
// getCacheKey()
// =========================================================================

describe('getCacheKey', function () {
    it('returns formatted cache key', function () {
        $uuid = Str::uuid()->toString();
        expect(DiscoverOrcidsJob::getCacheKey($uuid))->toBe("orcid_discovery:{$uuid}");
    });
});

// =========================================================================
// handle()
// =========================================================================

describe('handle', function () {
    it('sets cache status to running then completed', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $service = Mockery::mock(OrcidDiscoveryService::class);
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

        $job = new DiscoverOrcidsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('completed')
            ->and($cached['newOrcidsFound'])->toBe(5)
            ->and($cached['startedAt'])->toBeString()
            ->and($cached['completedAt'])->toBeString();
    });

    it('updates progress via callback', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $service = Mockery::mock(OrcidDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) use ($cacheKey) {
                $callback(2, 5);

                $cached = Cache::get($cacheKey);
                expect($cached['status'])->toBe('running')
                    ->and($cached['processedPersons'])->toBe(2)
                    ->and($cached['totalPersons'])->toBe(5)
                    ->and($cached['progress'])->toBe('Checking person 2 of 5...');

                return 0;
            });

        $job = new DiscoverOrcidsJob($uuid);
        $job->handle($service);
    });

    it('re-throws exception without overwriting cache status', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $service = Mockery::mock(OrcidDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andThrow(new RuntimeException('ORCID API connection failed'));

        $job = new DiscoverOrcidsJob($uuid);

        expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'ORCID API connection failed');

        // Cache should still be in 'running' state — the failed() callback
        // (triggered by the queue worker) is responsible for setting 'failed'
        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('running')
            ->and($cached['startedAt'])->toBeString();
    });

    it('tracks totalPersons correctly in completed status', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $service = Mockery::mock(OrcidDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) {
                $callback(1, 10);
                $callback(2, 10);
                $callback(10, 10);

                return 3;
            });

        $job = new DiscoverOrcidsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached['totalPersons'])->toBe(10)
            ->and($cached['processedPersons'])->toBe(10);
    });

    it('releases lock after successful completion', function () {
        $uuid = Str::uuid()->toString();
        $lockOwner = Str::random(20);

        Cache::lock('orcid_discovery_running', 7200)->forceRelease();
        $lock = Cache::lock('orcid_discovery_running', 7200);
        $lock->acquire();

        $service = Mockery::mock(OrcidDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturn(0);

        $job = new DiscoverOrcidsJob($uuid, $lock->owner());
        $job->handle($service);

        // Lock should be released — a new lock should be acquirable
        $newLock = Cache::lock('orcid_discovery_running', 7200);
        expect($newLock->get())->toBeTrue();
        $newLock->release();
    });

    it('releases lock after exception', function () {
        $uuid = Str::uuid()->toString();

        Cache::lock('orcid_discovery_running', 7200)->forceRelease();
        $lock = Cache::lock('orcid_discovery_running', 7200);
        $lock->acquire();

        $service = Mockery::mock(OrcidDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andThrow(new RuntimeException('Fail'));

        $job = new DiscoverOrcidsJob($uuid, $lock->owner());

        try {
            $job->handle($service);
        } catch (RuntimeException) {
            // expected
        }

        // Lock should be released
        $newLock = Cache::lock('orcid_discovery_running', 7200);
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
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $job = new DiscoverOrcidsJob($uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout');
    });

    it('handles null exception', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        $job = new DiscoverOrcidsJob($uuid);
        $job->failed(null);

        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Unknown error');
    });

    it('preserves running state data when merging failure info', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverOrcidsJob::getCacheKey($uuid);

        // Simulate a running state left by handle()
        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Checking person 3 of 10...',
            'totalPersons' => 10,
            'processedPersons' => 3,
            'newOrcidsFound' => 0,
            'startedAt' => '2026-03-29T00:00:00+00:00',
        ], now()->addHours(2));

        $job = new DiscoverOrcidsJob($uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout')
            ->and($cached['startedAt'])->toBe('2026-03-29T00:00:00+00:00')
            ->and($cached['totalPersons'])->toBe(10)
            ->and($cached['processedPersons'])->toBe(3)
            ->and($cached['completedAt'])->toBeString();
    });
});
