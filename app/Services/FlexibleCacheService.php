<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Stale-while-revalidate cache with a lock for the otherwise-unprotected cold miss.
 */
final class FlexibleCacheService
{
    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolver
     * @return TValue
     */
    public function remember(
        Repository $repository,
        string $key,
        int $freshSeconds,
        int $staleSeconds,
        Closure $resolver,
        int $lockSeconds,
        int $lockWaitSeconds,
    ): mixed {
        if ($staleSeconds <= 0) {
            return $resolver();
        }

        $freshSeconds = max(0, min($freshSeconds, $staleSeconds));
        $lockSeconds = max(1, $lockSeconds);
        $lockWaitSeconds = max(1, $lockWaitSeconds);

        if (! $repository instanceof CacheRepository) {
            return $repository->remember($key, $staleSeconds, $resolver);
        }

        if ($repository->has($key)) {
            return $repository->flexible(
                $key,
                [$freshSeconds, $staleSeconds],
                $resolver,
                ['seconds' => $lockSeconds],
            );
        }

        $lockKey = 'flexible-cache:cold:'.hash('sha256', $repository->getStore()::class.'|'.$key);

        try {
            return Cache::lock($lockKey, $lockSeconds)->block(
                $lockWaitSeconds,
                fn (): mixed => $repository->flexible(
                    $key,
                    [$freshSeconds, $staleSeconds],
                    $resolver,
                    ['seconds' => $lockSeconds],
                ),
            );
        } catch (LockTimeoutException $exception) {
            $valueAfterTimeout = $repository->get($key);
            if ($valueAfterTimeout !== null) {
                return $valueAfterTimeout;
            }

            throw $exception;
        }
    }
}
