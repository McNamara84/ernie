<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\Lock as LockContract;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    Cache::flush();
    config([
        'fuji.assessment.requests_per_minute' => 2,
        'fuji.assessment.window_seconds' => 60,
        'fuji.assessment.minimum_interval_ms' => 750,
    ]);
});

test('the limiter enforces spacing and a rolling request cap', function (): void {
    $clock = new class extends FujiAssessmentRequestLimiterService
    {
        public int $milliseconds = 1_000_000;

        protected function nowMs(): int
        {
            return $this->milliseconds;
        }
    };

    expect($clock->reserveSlot())->toBe(0)
        ->and($clock->reserveSlot())->toBe(750);
    expect(Cache::has(CacheKey::FUJI_ASSESSMENT_LIMITER_HISTORY->key()))->toBeTrue();

    $clock->milliseconds += 750;
    expect($clock->reserveSlot())->toBe(0)
        ->and($clock->reserveSlot())->toBe(59_250);

    $clock->milliseconds += 59_250;
    expect($clock->reserveSlot())->toBe(0);
});

test('a global cooldown takes precedence over otherwise available slots', function (): void {
    config(['fuji.assessment.minimum_interval_ms' => 0]);
    $clock = new class extends FujiAssessmentRequestLimiterService
    {
        public int $milliseconds = 2_000_000;

        protected function nowMs(): int
        {
            return $this->milliseconds;
        }
    };

    $clock->imposeCooldown(30);

    expect(Cache::has(CacheKey::FUJI_ASSESSMENT_LIMITER_COOLDOWN->key()))->toBeTrue()
        ->and($clock->reserveSlot())->toBe(30_000);
    $clock->milliseconds += 30_000;
    expect($clock->reserveSlot())->toBe(0);
});

test('a cooldown lock timeout is treated as a best-effort no-op', function (): void {
    $lock = Mockery::mock(LockContract::class);
    $lock->shouldReceive('block')
        ->once()
        ->with(5, Mockery::type(Closure::class))
        ->andThrow(new LockTimeoutException);

    $originalCacheManager = Cache::getFacadeRoot();
    $cacheManager = Mockery::mock(CacheManager::class, [app()])->makePartial();
    $cacheManager->shouldReceive('lock')
        ->once()
        ->with(CacheKey::FUJI_ASSESSMENT_LIMITER_LOCK->key(), 10)
        ->andReturn($lock);
    Cache::swap($cacheManager);

    try {
        expect(fn () => app(FujiAssessmentRequestLimiterService::class)->imposeCooldown(30))
            ->not->toThrow(LockTimeoutException::class);
    } finally {
        Cache::swap($originalCacheManager);
    }
});

test('clearing the limiter removes request history and cooldown', function (): void {
    config(['fuji.assessment.minimum_interval_ms' => 0]);
    $limiter = app(FujiAssessmentRequestLimiterService::class);

    expect($limiter->reserveSlot())->toBe(0);
    $limiter->imposeCooldown(30);
    expect($limiter->reserveSlot())->toBeGreaterThan(0);

    $limiter->clear();

    expect($limiter->reserveSlot())->toBe(0);
});
