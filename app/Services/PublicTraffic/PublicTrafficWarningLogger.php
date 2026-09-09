<?php

declare(strict_types=1);

namespace App\Services\PublicTraffic;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PublicTrafficWarningLogger
{
    /** @var array<string, int> */
    private static array $fallbackLastLoggedAt = [];

    public function warning(string $key, string $message, Throwable $exception): void
    {
        $now = time();

        try {
            if (! Cache::add("public-traffic:warning:{$key}", true, 3600)) {
                return;
            }
        } catch (Throwable) {
            if (($now - (self::$fallbackLastLoggedAt[$key] ?? 0)) < 3600) {
                return;
            }

            self::$fallbackLastLoggedAt[$key] = $now;
        }

        Log::warning($message, [
            'exception_class' => $exception::class,
        ]);
    }
}
