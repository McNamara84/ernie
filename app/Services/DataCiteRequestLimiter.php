<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteRequestDeferredException;
use Closure;
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

    private const INTERACTIVE_KEY = 'datacite:member-api:interactive-waiting-until-ms';

    private const BACKGROUND_HISTORY_KEY = 'datacite:member-api:background-history-ms';

    public function waitForSlot(bool $deferLongWait = false): void
    {
        $this->runWhenReady(function (): bool|int {
            $delay = $this->reserveSlot();

            return $delay > 0 ? $delay : true;
        }, $deferLongWait);
    }

    /**
     * The attempt admits and sends atomically with its DOI checks. Positive
     * delays are returned only after releasing all locks; they are not permits.
     *
     * @template T
     *
     * @param  Closure(): (T|int)  $attempt
     * @return T
     */
    public function runWhenReady(Closure $attempt, bool $deferLongWait = false): mixed
    {
        $interactive = app(InteractiveApiRequestContextService::class)->isInteractive();
        $deadline = $this->nowMs() + min(app(InteractiveApiRequestContextService::class)->remainingMilliseconds(),
            max(0, (int) config('datacite.interactive_max_wait_ms', 5000)));
        do {
            if (app(InteractiveApiRequestContextService::class)->remainingMilliseconds() === 0) {
                throw new DataCiteRequestDeferredException(1000);
            }
            $result = $attempt();
            if (! is_int($result)) {
                return $result;
            }
            $delayMs = max(1, $result);

            if (DataCiteDoiWriteLockService::hasHeldLock()
                || ($deferLongWait && $delayMs > 1000)
                || ($interactive && $this->nowMs() + $delayMs > $deadline)) {
                throw new DataCiteRequestDeferredException($delayMs);
            }

            $this->pause(min($delayMs, 1000));
        } while (true);
    }

    public function imposeCooldown(int $seconds): void
    {
        $seconds = max(1, $seconds);
        $untilMs = $this->nowMs() + ($seconds * 1000);

        Cache::lock(self::LOCK_KEY, 10)->block(5, function () use ($untilMs): void {
            $current = (int) Cache::get(self::COOLDOWN_KEY, 0);

            if ($untilMs > $current) {
                $ttlMs = max(1000, $untilMs - $this->nowMs() + 60_000);
                Cache::put(self::COOLDOWN_KEY, $untilMs, now()->addMilliseconds($ttlMs));
            }
        });
    }

    public function clear(): void
    {
        Cache::forget(self::HISTORY_KEY);
        Cache::forget(self::COOLDOWN_KEY);
        Cache::forget(self::INTERACTIVE_KEY);
        Cache::forget(self::BACKGROUND_HISTORY_KEY);
    }

    /**
     * Reserve a request slot or return the number of milliseconds until one
     * can be reserved. Public so the hard limit can be verified without
     * sleeping in automated tests and health checks.
     */
    public function reserveSlot(bool $nonBlocking = false): int
    {
        try {
            $lock = Cache::lock(self::LOCK_KEY, 10);
            $reserve = function (): int {
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

                $interactive = app(InteractiveApiRequestContextService::class)->isInteractive();
                $background = Cache::get(self::BACKGROUND_HISTORY_KEY, []);
                $background = is_array($background) ? array_values(array_filter(
                    array_map('intval', $background),
                    static fn (int $timestamp): bool => $timestamp > $nowMs - $windowMs,
                )) : [];
                // Always permit at least one background request, including tiny
                // limits used for installations/tests; the total cap still applies.
                $reserved = min(intdiv($limit, 10), max(0, (int) config('datacite.interactive_reserved_requests', 30)));
                $backgroundLimit = $limit - $reserved;

                $nextByInterval = $history === [] ? $nowMs : end($history) + $minimumIntervalMs;
                $nextByWindow = count($history) < $limit ? $nowMs : $history[0] + $windowMs;
                $nextAt = max($nowMs, $nextByInterval, $nextByWindow);

                if (! $interactive) {
                    $nextAt = max($nextAt, (int) Cache::get(self::INTERACTIVE_KEY, 0));
                    if (count($background) >= $backgroundLimit) {
                        $nextAt = max($nextAt, $background[0] + $windowMs);
                    }
                }

                if ($nextAt > $nowMs) {
                    if ($interactive) {
                        Cache::put(self::INTERACTIVE_KEY, $nowMs + 2000, 3);
                    }

                    return $nextAt - $nowMs;
                }

                $history[] = $nowMs;
                Cache::put(self::HISTORY_KEY, $history, now()->addMilliseconds($windowMs * 2));
                if (! $interactive) {
                    $background[] = $nowMs;
                    Cache::put(self::BACKGROUND_HISTORY_KEY, $background, now()->addMilliseconds($windowMs * 2));
                }

                return 0;
            };
            $result = $nonBlocking ? $lock->get($reserve) : $lock->block(5, $reserve);

            return is_int($result) ? $result : 1000;
        } catch (LockTimeoutException) {
            return 1000;
        }
    }

    protected function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    protected function pause(int $milliseconds): void
    {
        usleep($milliseconds * 1000);
    }
}
