<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/** One public read budget/cooldown across discovery modules and worker processes. */
final class DataCitePublicApiLimiterService
{
    private const KEY = 'datacite:public-discovery';

    public function tryAcquire(): bool
    {
        if (Cache::has(self::KEY.':unavailable')) {
            return false;
        }

        // Check and increment together; no HTTP or sleeping inside this lock.
        $allowed = Cache::lock(self::KEY.':lock', 5)->get(
            fn (): bool => ! Cache::has(self::KEY.':unavailable')
                && RateLimiter::attempt(self::KEY.':requests', 300, fn (): bool => true, 300),
        );
        if (! $allowed && RateLimiter::tooManyAttempts(self::KEY.':requests', 300)) {
            $this->suspend(max(1, RateLimiter::availableIn(self::KEY.':requests')));
        }

        return $allowed === true;
    }

    public function observe(Response $response): void
    {
        if ($response->status() !== 429 && ! $response->serverError()) {
            return;
        }
        $header = trim((string) $response->header('Retry-After'));
        $seconds = ctype_digit($header) ? (int) $header : max(1, (strtotime($header) ?: time() + 60) - time());
        $this->suspend($seconds);
    }

    public function suspend(int $seconds): void
    {
        $until = now()->getTimestamp() + max(1, $seconds);
        Cache::lock(self::KEY.':lock', 5)->block(1, function () use ($until): void {
            $current = (int) Cache::get(self::KEY.':unavailable', 0);
            if ($until > $current) {
                Cache::put(self::KEY.':unavailable', $until, max(1, $until - now()->getTimestamp()));
            }
        });
    }

    public function userAgent(): string
    {
        $email = trim((string) config('datacite.landing_page_url_update.support_email', ''));

        return 'ERNIE ('.config('app.url').($email === '' ? '' : '; mailto:'.$email).')';
    }
}
