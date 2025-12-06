<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Service for managing vocabulary-related caching.
 *
 * This service handles caching of GCMD and MSL vocabularies,
 * which are relatively static and benefit from long-term caching.
 */
class VocabularyCacheService
{
    /**
     * Cache GCMD science keywords.
     *
     * @param callable $callback Callback to load keywords
     * @return Collection
     */
    public function cacheGcmdScienceKeywords(callable $callback): Collection
    {
        return $this->cacheVocabulary(CacheKey::GCMD_SCIENCE_KEYWORDS, $callback);
    }

    /**
     * Cache GCMD instruments.
     *
     * @param callable $callback Callback to load instruments
     * @return Collection
     */
    public function cacheGcmdInstruments(callable $callback): Collection
    {
        return $this->cacheVocabulary(CacheKey::GCMD_INSTRUMENTS, $callback);
    }

    /**
     * Cache GCMD platforms.
     *
     * @param callable $callback Callback to load platforms
     * @return Collection
     */
    public function cacheGcmdPlatforms(callable $callback): Collection
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PLATFORMS, $callback);
    }

    /**
     * Cache GCMD providers.
     *
     * @param callable $callback Callback to load providers
     * @return Collection
     */
    public function cacheGcmdProviders(callable $callback): Collection
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PROVIDERS, $callback);
    }

    /**
     * Cache MSL keywords.
     *
     * @param callable $callback Callback to load keywords
     * @return Collection
     */
    public function cacheMslKeywords(callable $callback): Collection
    {
        return $this->cacheVocabulary(CacheKey::MSL_KEYWORDS, $callback);
    }

    /**
     * Generic method to cache any vocabulary.
     *
     * @param CacheKey $key Cache key enum
     * @param callable $callback Callback to load vocabulary data
     * @return mixed
     */
    public function cacheVocabulary(CacheKey $key, callable $callback): mixed
    {
        return Cache::tags($key->tags())
            ->remember(
                $key->key(),
                $key->ttl(),
                $callback
            );
    }

    /**
     * Invalidate all vocabulary caches.
     *
     * This should be called after vocabulary sync commands.
     *
     * @return void
     */
    public function invalidateAllVocabularyCaches(): void
    {
        Cache::tags(['vocabularies'])->flush();
    }

    /**
     * Invalidate a specific vocabulary cache.
     *
     * @param CacheKey $key The vocabulary cache key
     * @return void
     */
    public function invalidateVocabularyCache(CacheKey $key): void
    {
        Cache::tags($key->tags())->forget($key->key());
    }
}
