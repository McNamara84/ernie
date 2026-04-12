<?php

declare(strict_types=1);

use App\Jobs\DiscoverAssistantSuggestionsJob;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Assistance\GenericTableAssistant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

covers(DiscoverAssistantSuggestionsJob::class);

beforeEach(function (): void {
    Cache::flush();
});

// =========================================================================
// Constructor validation
// =========================================================================

describe('constructor validation', function () {
    it('accepts a valid UUID', function () {
        $job = new DiscoverAssistantSuggestionsJob('test-assistant', Str::uuid()->toString());
        expect($job)->toBeInstanceOf(DiscoverAssistantSuggestionsJob::class);
    });

    it('rejects invalid UUID format')
        ->throws(InvalidArgumentException::class, 'Job ID must be a valid UUID')
        ->expect(fn () => new DiscoverAssistantSuggestionsJob('test-assistant', 'not-a-uuid'));
});

// =========================================================================
// getCacheKey()
// =========================================================================

describe('getCacheKey', function () {
    it('returns cache key using assistant manifest prefix', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn("test_cache_prefix:{$uuid}");

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        expect($job->getCacheKey())->toBe("test_cache_prefix:{$uuid}");
    });

    it('falls back to assistantId:jobId when assistant not found', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'unknown-assistant';

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturnNull();

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        expect($job->getCacheKey())->toBe("{$assistantId}:{$uuid}");
    });
});

// =========================================================================
// handle()
// =========================================================================

describe('handle', function () {
    it('sets cache status to running then completed', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('runDiscovery')
            ->once()
            ->andReturnUsing(function (Closure $onProgress) use ($cacheKey) {
                // While running, cache should show running status
                $cached = Cache::get($cacheKey);
                expect($cached)->toBeArray()
                    ->and($cached['status'])->toBe('running');

                $onProgress('Processing item 1 of 3...');

                return 5;
            });
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->handle($registrar);

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('completed')
            ->and($cached['newSuggestionsFound'])->toBe(5)
            ->and($cached['startedAt'])->toBeString()
            ->and($cached['completedAt'])->toBeString();
    });

    it('updates progress via callback', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('runDiscovery')
            ->once()
            ->andReturnUsing(function (Closure $onProgress) use ($cacheKey) {
                $onProgress('Checking resource 2 of 10...');

                $cached = Cache::get($cacheKey);
                expect($cached['status'])->toBe('running')
                    ->and($cached['progress'])->toBe('Checking resource 2 of 10...');

                return 0;
            });
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->handle($registrar);
    });

    it('sets failed status when assistant is not a GenericTableAssistant', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        // Return a non-GenericTableAssistant (null means not found)
        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturnNull();

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->handle($registrar);

        // getCacheKey uses fallback since assistant not found
        $fallbackKey = "{$assistantId}:{$uuid}";
        $cached = Cache::get($fallbackKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toContain('not registered');
    });

    it('re-throws exception from discovery', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('runDiscovery')
            ->once()
            ->andThrow(new RuntimeException('API connection failed'));
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);

        expect(fn () => $job->handle($registrar))->toThrow(RuntimeException::class, 'API connection failed');
    });

    it('releases lock after successful completion', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";
        $lockKey = 'test_discovery_running';

        // Acquire a real lock so we can verify release
        $lock = Cache::lock($lockKey, 7200);
        $lock->get();
        $lockOwner = $lock->owner();

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('runDiscovery')
            ->once()
            ->andReturn(0);
        $mockAssistant->shouldReceive('getLockKey')->andReturn($lockKey);

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid, $lockOwner);
        $job->handle($registrar);

        // Lock should have been released — acquiring again should succeed
        $newLock = Cache::lock($lockKey, 7200);
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
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout');
    });

    it('handles null exception', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->failed(null);

        $cached = Cache::get($cacheKey);
        expect($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Unknown error');
    });

    it('preserves running state data when merging failure info', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $cacheKey = "test_cache:{$uuid}";

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn($cacheKey);
        $mockAssistant->shouldReceive('getLockKey')->andReturn('test_lock');

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        // Simulate a running state left by handle()
        Cache::put($cacheKey, [
            'status' => 'running',
            'progress' => 'Checking resource 3 of 10...',
            'startedAt' => '2026-04-12T00:00:00+00:00',
        ], now()->addHours(2));

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid);
        $job->failed(new RuntimeException('Queue timeout'));

        $cached = Cache::get($cacheKey);
        expect($cached)->toBeArray()
            ->and($cached['status'])->toBe('failed')
            ->and($cached['error'])->toBe('Queue timeout')
            ->and($cached['startedAt'])->toBe('2026-04-12T00:00:00+00:00')
            ->and($cached['completedAt'])->toBeString();
    });

    it('releases lock on failure', function () {
        $uuid = Str::uuid()->toString();
        $assistantId = 'test-assistant';
        $lockKey = 'test_discovery_running';

        $lock = Cache::lock($lockKey, 7200);
        $lock->get();
        $lockOwner = $lock->owner();

        $mockAssistant = Mockery::mock(GenericTableAssistant::class);
        $mockAssistant->shouldReceive('getJobStatusCacheKey')
            ->with($uuid)
            ->andReturn("test_cache:{$uuid}");
        $mockAssistant->shouldReceive('getLockKey')->andReturn($lockKey);

        $registrar = Mockery::mock(AssistantRegistrar::class);
        $registrar->shouldReceive('get')
            ->with($assistantId)
            ->andReturn($mockAssistant);

        $this->app->instance(AssistantRegistrar::class, $registrar);

        $job = new DiscoverAssistantSuggestionsJob($assistantId, $uuid, $lockOwner);
        $job->failed(new RuntimeException('Queue timeout'));

        // Lock should be released
        $newLock = Cache::lock($lockKey, 7200);
        expect($newLock->get())->toBeTrue();
        $newLock->release();
    });
});
