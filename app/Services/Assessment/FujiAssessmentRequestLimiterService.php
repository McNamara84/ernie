<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\CacheKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class FujiAssessmentRequestLimiterService
{
    public function reserveSlot(): int
    {
        try {
            return Cache::lock(CacheKey::FUJI_ASSESSMENT_LIMITER_LOCK->key(), 10)->block(5, function (): int {
                $nowMs = $this->nowMs();
                $cooldownUntilMs = (int) Cache::get(CacheKey::FUJI_ASSESSMENT_LIMITER_COOLDOWN->key(), 0);

                if ($cooldownUntilMs > $nowMs) {
                    return $cooldownUntilMs - $nowMs;
                }

                $limit = max(1, (int) config('fuji.assessment.requests_per_minute', 80));
                $windowMs = max(1000, (int) config('fuji.assessment.window_seconds', 60) * 1000);
                $minimumIntervalMs = max(0, (int) config('fuji.assessment.minimum_interval_ms', 750));
                $history = Cache::get(CacheKey::FUJI_ASSESSMENT_LIMITER_HISTORY->key(), []);
                $history = is_array($history) ? array_values(array_filter(
                    array_map('intval', $history),
                    static fn (int $timestamp): bool => $timestamp > $nowMs - $windowMs,
                )) : [];

                $nextByInterval = $history === [] ? $nowMs : ((int) end($history)) + $minimumIntervalMs;
                $nextByWindow = count($history) < $limit ? $nowMs : $history[0] + $windowMs;
                $nextAt = max($nowMs, $nextByInterval, $nextByWindow);

                if ($nextAt > $nowMs) {
                    return $nextAt - $nowMs;
                }

                $history[] = $nowMs;
                Cache::put(CacheKey::FUJI_ASSESSMENT_LIMITER_HISTORY->key(), $history, now()->addMilliseconds($windowMs * 2));

                return 0;
            });
        } catch (LockTimeoutException) {
            return 1000;
        }
    }

    public function imposeCooldown(int $seconds): void
    {
        $untilMs = $this->nowMs() + (max(1, $seconds) * 1000);

        try {
            Cache::lock(CacheKey::FUJI_ASSESSMENT_LIMITER_LOCK->key(), 10)->block(5, function () use ($untilMs): void {
                $cooldownKey = CacheKey::FUJI_ASSESSMENT_LIMITER_COOLDOWN->key();
                $current = (int) Cache::get($cooldownKey, 0);

                if ($untilMs > $current) {
                    Cache::put($cooldownKey, $untilMs, now()->addMilliseconds(max(1000, $untilMs - $this->nowMs() + 60_000)));
                }
            });
        } catch (LockTimeoutException) {
            // Another limiter operation holds the lock; the caller still handles the 429 at item level.
        }
    }

    public function clear(): void
    {
        CacheKey::FUJI_ASSESSMENT_LIMITER_HISTORY->forget();
        CacheKey::FUJI_ASSESSMENT_LIMITER_COOLDOWN->forget();
    }

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
