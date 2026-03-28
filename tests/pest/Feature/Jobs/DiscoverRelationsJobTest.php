<?php

declare(strict_types=1);

use App\Jobs\DiscoverRelationsJob;
use App\Models\Resource;
use App\Models\SuggestedRelation;
use App\Services\RelationDiscoveryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(DiscoverRelationsJob::class);

beforeEach(function (): void {
    Cache::flush();
});

// =========================================================================
// Constructor validation
// =========================================================================

describe('constructor validation', function () {
    it('accepts a valid UUID', function () {
        $job = new DiscoverRelationsJob(Str::uuid()->toString());
        expect($job)->toBeInstanceOf(DiscoverRelationsJob::class);
    });

    it('rejects invalid UUID format')
        ->throws(InvalidArgumentException::class, 'Job ID must be a valid UUID')
        ->expect(fn () => new DiscoverRelationsJob('not-a-uuid'));
});

// =========================================================================
// getCacheKey()
// =========================================================================

describe('getCacheKey', function () {
    it('returns formatted cache key', function () {
        $uuid = Str::uuid()->toString();
        expect(DiscoverRelationsJob::getCacheKey($uuid))->toBe("relation_discovery:{$uuid}");
    });
});

// =========================================================================
// handle()
// =========================================================================

describe('handle', function () {
    it('sets cache status to running then completed', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $service = Mockery::mock(RelationDiscoveryService::class);
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

        $job = new DiscoverRelationsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('completed')
            ->and($cached['newRelationsFound'])->toBe(5)
            ->and($cached['startedAt'])->toBeString()
            ->and($cached['completedAt'])->toBeString();
    });

    it('updates progress via callback', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $service = Mockery::mock(RelationDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) use ($cacheKey) {
                $callback(2, 5);

                $cached = Cache::get($cacheKey);
                expect($cached['status'])->toBe('running')
                    ->and($cached['processedDois'])->toBe(2)
                    ->and($cached['totalDois'])->toBe(5)
                    ->and($cached['progress'])->toBe('Checking DOI 2 of 5...');

                return 0;
            });

        $job = new DiscoverRelationsJob($uuid);
        $job->handle($service);
    });

    it('sets cache status to failed on exception and re-throws', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $service = Mockery::mock(RelationDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andThrow(new RuntimeException('API connection failed'));

        $job = new DiscoverRelationsJob($uuid);

        expect(fn () => $job->handle($service))->toThrow(RuntimeException::class, 'API connection failed');

        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('API connection failed')
            ->and($cached['completedAt'])->toBeString();
    });

    it('tracks totalDois correctly in completed status', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $service = Mockery::mock(RelationDiscoveryService::class);
        $service->shouldReceive('discoverAll')
            ->once()
            ->andReturnUsing(function (?callable $callback) {
                $callback(1, 10);
                $callback(2, 10);
                $callback(10, 10);

                return 3;
            });

        $job = new DiscoverRelationsJob($uuid);
        $job->handle($service);

        $cached = Cache::get($cacheKey);
        expect($cached['totalDois'])->toBe(10)
            ->and($cached['processedDois'])->toBe(10);
    });
});

// =========================================================================
// failed()
// =========================================================================

describe('failed', function () {
    it('sets cache status to failed with error message', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $job = new DiscoverRelationsJob($uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout');
    });

    it('handles null exception', function () {
        $uuid = Str::uuid()->toString();
        $cacheKey = DiscoverRelationsJob::getCacheKey($uuid);

        $job = new DiscoverRelationsJob($uuid);
        $job->failed(null);

        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Unknown error');
    });
});
