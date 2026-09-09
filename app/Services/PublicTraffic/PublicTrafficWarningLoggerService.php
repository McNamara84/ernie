<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use App\Enums\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicTrafficWarningLoggerService
{
    /** @var array<string, int> */
    private static array $fallbackLastLoggedAt = [];

    public function warning(string $key, string $message, Throwable $exception): void
    {
        $now = time();
        $cacheKey = CacheKey::PUBLIC_TRAFFIC_WARNING;

        try {
            if (! Cache::add($cacheKey->key($key), true, $cacheKey->ttl())) {
                return;
            }
        } catch (Throwable) {
            if (($now - (self::$fallbackLastLoggedAt[$key] ?? 0)) < $cacheKey->ttl()) {
                return;
            }

            self::$fallbackLastLoggedAt[$key] = $now;
        }

        Log::warning($message, [
            'exception_class' => $exception::class,
        ]);
    }
}
