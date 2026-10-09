<?php

declare(strict_types=1);

use App\Enums\ImportCancellationResult;
use App\Models\User;
use App\Services\ImportProgressService;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutExceptionHandling();
    $this->actor = User::factory()->admin()->create();
    $this->importId = (string) Str::uuid();
    $this->cache = Mockery::mock(Cache::getFacadeRoot());
    Cache::swap($this->cache);
    Log::spy();
});

it('persists cancellation and retains progress before reporting success', function (string $type, string $prefix, string $status) {
    $service = app(ImportProgressService::class);
    $key = $service->progressKey($type, $this->importId);
    Cache::put($key, ['status' => $status, 'processed' => 3, 'imported' => 2]);

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$this->importId}/cancel")
        ->assertOk()->assertJsonPath('message', 'Import cancelled');

    $progress = Cache::get($key);
    expect($progress['status'])->toBe('cancelled')->and($progress['processed'])->toBe(3)
        ->and($progress['imported'])->toBe(2)->and($progress['completed_at'])->not->toBeNull();
})->with(['resource' => ['resource', 'datacite'], 'IGSN' => ['igsn', 'igsns']])->with(['running', 'pending']);

it('does not overwrite a terminal import or log cancellation', function (string $type, string $prefix, string $status) {
    $key = app(ImportProgressService::class)->progressKey($type, $this->importId);
    $progress = ['status' => $status, 'imported' => 2, 'completed_at' => now()->toIso8601String()];
    Cache::put($key, $progress);

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$this->importId}/cancel")->assertStatus(400);

    expect(Cache::get($key))->toBe($progress);
    Log::shouldNotHaveReceived('info');
})->with(['resource' => ['resource', 'datacite'], 'IGSN' => ['igsn', 'igsns']])->with(['completed', 'failed', 'cancelled']);

it('reads the latest state only after acquiring the cancellation lock', function (string $type, string $prefix, bool $disappeared) {
    $key = app(ImportProgressService::class)->progressKey($type, $this->importId);
    Cache::put($key, ['status' => 'running']);
    $lock = Mockery::mock(LockContract::class);
    $lock->shouldReceive('block')->once()->with(5, Mockery::type(Closure::class))
        ->andReturnUsing(function (int $seconds, Closure $callback) use ($key, $disappeared) {
            // Simulate a worker completing or expiry while the request waits for the lock.
            if ($disappeared) {
                Cache::forget($key);
            } else {
                Cache::put($key, ['status' => 'completed', 'imported' => 4]);
            }

            return $callback();
        });
    $this->cache->shouldReceive('lock')->once()->with("{$key}:lock", 15)->andReturn($lock);

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$this->importId}/cancel")
        ->assertStatus($disappeared ? 404 : 400);

    expect(Cache::get($key))->toBe($disappeared ? null : ['status' => 'completed', 'imported' => 4]);
    Log::shouldNotHaveReceived('info');
})->with(['resource' => ['resource', 'datacite'], 'IGSN' => ['igsn', 'igsns']])->with([false, true]);

it('returns unavailable after exhausted cancellation lock retries without success activity', function (string $type, string $prefix) {
    $key = app(ImportProgressService::class)->progressKey($type, $this->importId);
    Cache::put($key, ['status' => 'running']);
    $lock = Mockery::mock(LockContract::class);
    $lock->shouldReceive('block')->times(3)->with(5, Mockery::type(Closure::class))->andThrow(new LockTimeoutException);
    $this->cache->shouldReceive('lock')->times(3)->with("{$key}:lock", 15)->andReturn($lock);

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$this->importId}/cancel")
        ->assertStatus(503)->assertJsonPath('error', 'Unable to cancel the import. Please try again.');

    expect(Cache::get($key)['status'])->toBe('running');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['operation'] === 'cancel' && $context['attempts'] === 3);
    Log::shouldNotHaveReceived('info');
})->with(['resource' => ['resource', 'datacite'], 'IGSN' => ['igsn', 'igsns']]);

it('does not report cancellation when persisting the transition fails', function (string $type, string $prefix) {
    $key = app(ImportProgressService::class)->progressKey($type, $this->importId);
    Cache::put($key, ['status' => 'running']);
    $this->cache->shouldReceive('put')->once()
        ->withArgs(fn (string $candidate, array $progress, mixed $ttl): bool => $candidate === $key && $progress['status'] === 'cancelled')
        ->andReturnFalse();

    $this->actingAs($this->actor)->postJson("/{$prefix}/import/{$this->importId}/cancel")->assertStatus(503);

    expect(Cache::get($key)['status'])->toBe('running');
    Log::shouldNotHaveReceived('info');
})->with(['resource' => ['resource', 'datacite'], 'IGSN' => ['igsn', 'igsns']]);

it('preserves cancellation when a worker submits a stale state', function (string $type, bool $replace) {
    $service = app(ImportProgressService::class);
    $key = $service->progressKey($type, $this->importId);
    Cache::put($key, ['status' => 'running', 'processed' => 1]);
    expect($service->cancelIfRunning($type, $this->importId))->toBe(ImportCancellationResult::CANCELLED);
    $completedAt = Cache::get($key)['completed_at'];

    $service->update($type, $this->importId, ['status' => 'completed', 'phase' => 'syncing', 'processed' => 2, 'completed_at' => null], $replace);

    $progress = Cache::get($key);
    expect($progress['status'])->toBe('cancelled')->and($progress['phase'])->toBe('completed')
        ->and($progress['processed'])->toBe(2)->and($progress['completed_at'])->toBe($completedAt);
})->with(['resource', 'igsn'])->with([false, true]);
