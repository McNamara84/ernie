<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteRequestDeferredException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Shared leaky-bucket limiter for every authenticated DataCite request.
 *
 * A shared cache store is required in multi-process/host deployments. The
 * minimum interval prevents a permitted five-minute quota from being emitted
 * as a burst, while the rolling timestamp window provides a hard upper bound.
 */
class DataCiteRequestLimiter
{
    private const HISTORY_KEY = 'datacite:member-api:request-history-ms';

    private const COOLDOWN_KEY = 'datacite:member-api:cooldown-until-ms';

    private const LOCK_KEY = 'datacite:member-api:request-limiter-lock';

    public function waitForSlot(bool $deferLongWait = false): void
    {
        do {
            $delayMs = $this->reserveSlot();

            if ($delayMs > 0) {
                if ($deferLongWait && $delayMs > 1000) {
                    throw new DataCiteRequestDeferredException($delayMs);
                }

                usleep(min($delayMs, 1000) * 1000);
            }
        } while ($delayMs > 0);
    }

    public function imposeCooldown(int $seconds): void
    {
        $seconds = max(1, $seconds);
        $untilMs = $this->nowMs() + ($seconds * 1000);
        $current = (int) Cache::get(self::COOLDOWN_KEY, 0);

        if ($untilMs > $current) {
            Cache::put(self::COOLDOWN_KEY, $untilMs, now()->addSeconds($seconds + 60));
        }
    }

    public function clear(): void
    {
        Cache::forget(self::HISTORY_KEY);
        Cache::forget(self::COOLDOWN_KEY);
    }

    /**
     * Reserve a request slot or return the number of milliseconds until one
     * can be reserved. Public so the hard limit can be verified without
     * sleeping in automated tests and health checks.
     */
    public function reserveSlot(): int
    {
        try {
            return Cache::lock(self::LOCK_KEY, 10)->block(5, function (): int {
                $nowMs = $this->nowMs();
                $cooldownUntilMs = (int) Cache::get(self::COOLDOWN_KEY, 0);

                if ($cooldownUntilMs > $nowMs) {
                    return $cooldownUntilMs - $nowMs;
                }

                $limit = max(1, (int) config('datacite.landing_page_url_update.requests_per_window', 300));
                $windowMs = max(1000, (int) config('datacite.landing_page_url_update.window_seconds', 300) * 1000);
                $minimumIntervalMs = max(0, (int) config('datacite.landing_page_url_update.minimum_interval_ms', 1000));
                $history = Cache::get(self::HISTORY_KEY, []);
                $history = is_array($history) ? array_values(array_filter(
                    array_map('intval', $history),
                    static fn (int $timestamp): bool => $timestamp > $nowMs - $windowMs,
                )) : [];

                $nextByInterval = $history === [] ? $nowMs : end($history) + $minimumIntervalMs;
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

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
