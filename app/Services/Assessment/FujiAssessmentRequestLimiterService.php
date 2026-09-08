<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class FujiAssessmentRequestLimiterService
{
    private const HISTORY_KEY = 'fuji:assessment:request-history-ms';

    private const COOLDOWN_KEY = 'fuji:assessment:cooldown-until-ms';

    private const LOCK_KEY = 'fuji:assessment:request-limiter-lock';

    public function reserveSlot(): int
    {
        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(5, function (): int {
                $nowMs = $this->nowMs();
                $cooldownUntilMs = (int) Cache::get(self::COOLDOWN_KEY, 0);

                if ($cooldownUntilMs > $nowMs) {
                    return $cooldownUntilMs - $nowMs;
                }

                $limit = max(1, (int) config('fuji.assessment.requests_per_minute', 80));
                $windowMs = max(1000, (int) config('fuji.assessment.window_seconds', 60) * 1000);
                $minimumIntervalMs = max(0, (int) config('fuji.assessment.minimum_interval_ms', 750));
                $history = Cache::get(self::HISTORY_KEY, []);
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
                Cache::put(self::HISTORY_KEY, $history, now()->addMilliseconds($windowMs * 2));

                return 0;
            });
        } catch (LockTimeoutException) {
            return 1000;
        }
    }

    public function imposeCooldown(int $seconds): void
    {
        $untilMs = $this->nowMs() + (max(1, $seconds) * 1000);

        Cache::lock(self::LOCK_KEY, 10)->block(5, function () use ($untilMs): void {
            $current = (int) Cache::get(self::COOLDOWN_KEY, 0);

            if ($untilMs > $current) {
                Cache::put(self::COOLDOWN_KEY, $untilMs, now()->addMilliseconds(max(1000, $untilMs - $this->nowMs() + 60_000)));
            }
        });
    }

    public function clear(): void
    {
        Cache::forget(self::HISTORY_KEY);
        Cache::forget(self::COOLDOWN_KEY);
    }

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
