<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Caches exact listing counts independently from result pages.
 */
final class ListingCountService
{
    private bool $internalInvalidationScheduled = false;

    private const NON_COUNT_KEYS = [
        'page',
        'per_page',
        'sort',
        'direction',
        'sort_key',
        'sort_direction',
        'sortKey',
        'sortDirection',
    ];

    /**
     * @param  array<string, mixed>  $criteria
     */
    public function fingerprint(array $criteria): string
    {
        foreach (self::NON_COUNT_KEYS as $key) {
            unset($criteria[$key]);
        }

        $normalized = $this->normalize($criteria);
        $encoded = json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $encoded);
    }

    /**
     * @param  array<string, mixed>  $criteria
     * @param  Closure(): int  $resolver
     */
    public function remember(CacheKey $cacheKey, array $criteria, Closure $resolver): int
    {
        $fingerprint = $this->fingerprint($criteria);
        $key = $cacheKey->key($fingerprint);
        $repository = $this->repository($cacheKey);
        $cached = $repository->get($key);

        if (is_int($cached) || is_numeric($cached)) {
            return (int) $cached;
        }

        $resolveAndStore = function () use ($repository, $key, $cacheKey, $resolver): int {
            $cachedInsideLock = $repository->get($key);

            if (is_int($cachedInsideLock) || is_numeric($cachedInsideLock)) {
                return (int) $cachedInsideLock;
            }

            $count = max(0, (int) $resolver());
            $repository->put($key, $count, $cacheKey->ttl());

            return $count;
        };

        try {
            return (int) Cache::lock(
                'listing-count-lock:'.$cacheKey->value.':'.$fingerprint,
                (int) config('listing_performance.count_lock_seconds', 10),
            )->block(
                (int) config('listing_performance.count_lock_wait_seconds', 3),
                $resolveAndStore,
            );
        } catch (LockTimeoutException $exception) {
            $cachedAfterTimeout = $repository->get($key);

            if (is_int($cachedAfterTimeout) || is_numeric($cachedAfterTimeout)) {
                return (int) $cachedAfterTimeout;
            }

            // Do not run another expensive count outside the lock. The list is
            // already usable and its clients expose a recoverable failed state.
            throw $exception;
        }
    }

    /**
     * Coalesce relation-level invalidations and execute them after commit.
     */
    public function scheduleInternalInvalidationAfterCommit(): void
    {
        if ($this->internalInvalidationScheduled) {
            return;
        }

        $databaseManager = DB::getFacadeRoot();

        if (! $databaseManager instanceof DatabaseManager) {
            $this->invalidateInternal();

            return;
        }

        try {
            $connection = $databaseManager->connection();

            if ($connection->transactionLevel() === 0) {
                $this->invalidateInternal();

                return;
            }

            $this->internalInvalidationScheduled = true;
            $connection->afterCommit(function (): void {
                $this->internalInvalidationScheduled = false;
                $this->invalidateInternal();
            });
            $connection->afterRollBack(function (): void {
                $this->internalInvalidationScheduled = false;
            });
        } catch (Throwable) {
            $this->internalInvalidationScheduled = false;
            $this->invalidateInternal();
        }
    }

    private function repository(CacheKey $cacheKey): Repository
    {
        return method_exists(Cache::getStore(), 'tags')
            ? Cache::tags($cacheKey->tags())
            : Cache::store();
    }

    private function invalidateInternal(): void
    {
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags(['internal_listing_counts'])->flush();

            return;
        }

        // Non-tag stores are used only in local/testing deployments here.
        Cache::flush();
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = array_map(fn (mixed $item): mixed => $this->normalize($item), $value);

            if (array_all($normalized, static fn (mixed $item): bool => is_scalar($item) || $item === null)) {
                usort($normalized, static fn (mixed $left, mixed $right): int => strcmp((string) $left, (string) $right));
            }

            return $normalized;
        }

        ksort($value);

        foreach ($value as $key => $item) {
            if ($item === null || $item === '' || $item === []) {
                unset($value[$key]);

                continue;
            }

            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
