<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/** Shared spacing, with short atomic reservations and priority for waiting editors. */
class OrcidRequestLimiterService
{
    private const KEY = 'orcid_api_last_call_ms';

    public function waitForSlot(): void
    {
        $interactive = app(InteractiveApiRequestContextService::class)->isInteractive();
        $deadline = $this->nowMs() + min(app(InteractiveApiRequestContextService::class)->remainingMilliseconds(), $interactive ? 5000 : 30000);
        do {
            $delay = $this->reserveSlot();
            if ($delay === 0) {
                return;
            }
            if ($this->nowMs() + $delay > $deadline) {
                throw new RuntimeException('The ORCID verification service is busy. Please retry shortly.');
            }
            // Never sleep while holding the Redis lock. Waiting background
            // searches yield the next slot to an interactive verification.
            $this->pause(min($delay, 250));
        } while (true);
    }

    public function reserveSlot(): int
    {
        try {
            return Cache::lock(self::KEY.':lock', 5)->block(1, function (): int {
                $now = $this->nowMs();
                $interactive = app(InteractiveApiRequestContextService::class)->isInteractive();
                $delay = max(0, (int) ceil((float) Cache::get(self::KEY, 0)
                    + max(0, (int) config('services.orcid.rate_limit_delay_ms', 2100)) - $now));
                if (! $interactive) {
                    $delay = max($delay, (int) Cache::get(self::KEY.':interactive-until', 0) - $now);
                }
                if ($delay > 0) {
                    if ($interactive) {
                        Cache::put(self::KEY.':interactive-until', $now + 2500, 3);
                    }

                    return $delay;
                }

                Cache::put(self::KEY, $now, 60);

                return 0;
            });
        } catch (LockTimeoutException) {
            // A lock failure is never permission to bypass the provider's limit.
            return 250;
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
