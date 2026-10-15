<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\DataCiteWriteSupersededException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Serializes remote writes with local tombstone transitions, including queued writers. */
final class DataCiteDoiWriteLockService
{
    /** @var array<string, true> */
    private static array $held = [];

    public static function hasHeldLock(): bool
    {
        return self::$held !== [];
    }

    /** Observe under run(); merely waiting for admission must not supersede a writer. */
    public static function observeWrite(string $doi, bool $testMode): string
    {
        $revision = Cache::get(self::key($doi, $testMode).':revision');

        return is_string($revision) ? $revision : self::beginWrite($doi, $testMode);
    }

    /** Advance under run(), immediately before a real HTTP write attempt. */
    public static function beginWrite(string $doi, bool $testMode): string
    {
        $revision = (string) Str::uuid();
        Cache::put(self::key($doi, $testMode).':revision', $revision, now()->addHours(3));

        return $revision;
    }

    /** Missing/expired evidence also fails closed rather than replaying old metadata. */
    public static function assertCurrentWrite(string $doi, bool $testMode, string $revision): void
    {
        if (Cache::get(self::key($doi, $testMode).':revision') !== $revision) {
            throw new DataCiteWriteSupersededException;
        }
    }

    private static function key(string $doi, bool $testMode): string
    {
        return 'datacite:doi-write:'.hash('sha256', ($testMode ? 'test:' : 'production:').strtolower(trim($doi)));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $doi, bool $testMode, callable $callback): mixed
    {
        $key = self::key($doi, $testMode);
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
