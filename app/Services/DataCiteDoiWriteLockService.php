<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/** Serializes remote writes with local tombstone transitions, including queued writers. */
final class DataCiteDoiWriteLockService
{
    /** @var array<string, true> */
    private static array $held = [];

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $doi, bool $testMode, callable $callback): mixed
    {
        $key = 'datacite:doi-write:'.hash('sha256', ($testMode ? 'test:' : 'production:').strtolower($doi));
        if (isset(self::$held[$key])) {
            return $callback();
        }

        $waitSeconds = min(10, max(1, (int) ceil(app(InteractiveApiRequestContextService::class)->remainingMilliseconds() / 1000)));

        return Cache::lock($key, 300)->block($waitSeconds, static function () use ($key, $callback): mixed {
            self::$held[$key] = true;
            try {
                return $callback();
            } finally {
                unset(self::$held[$key]);
            }
        });
    }
}
