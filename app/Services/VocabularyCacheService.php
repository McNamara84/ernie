<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing vocabulary-related caching.
 *
 * This service handles caching of GCMD and MSL vocabularies,
 * which are relatively static and benefit from long-term caching.
 */
class VocabularyCacheService
{
    use ChecksCacheTagging;

    /**
     * Cache GCMD science keywords.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback  Callback to load keywords
     * @return TValue
     */
    public function cacheGcmdScienceKeywords(\Closure $callback): mixed
    {
        return $this->cacheVocabulary(CacheKey::GCMD_SCIENCE_KEYWORDS, $callback);
    }

    /**
     * Cache GCMD instruments.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback  Callback to load instruments
     * @return TValue
     */
    public function cacheGcmdInstruments(\Closure $callback): mixed
    {
        return $this->cacheVocabulary(CacheKey::GCMD_INSTRUMENTS, $callback);
    }

    /**
     * Cache GCMD platforms.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback  Callback to load platforms
     * @return TValue
     */
    public function cacheGcmdPlatforms(\Closure $callback): mixed
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PLATFORMS, $callback);
    }

    /**
     * Cache GCMD providers.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback  Callback to load providers
     * @return TValue
     */
    public function cacheGcmdProviders(\Closure $callback): mixed
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PROVIDERS, $callback);
    }

    /**
     * Cache MSL keywords.
     *
     * @template TValue
     *
     * @param  \Closure(): TValue  $callback  Callback to load keywords
     * @return TValue
     */
    public function cacheMslKeywords(\Closure $callback): mixed
    {
        return $this->cacheVocabulary(CacheKey::MSL_KEYWORDS, $callback);
    }

    /**
     * Generic method to cache any vocabulary.
     *
     * @template TValue
     *
     * @param  CacheKey  $key  Cache key enum
     * @param  \Closure(): TValue  $callback  Callback to load vocabulary data
     * @return TValue
     */
    public function cacheVocabulary(CacheKey $key, \Closure $callback): mixed
    {
        return $this->getCacheInstance($key->tags())
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
     * WARNING: When cache tagging is not supported (e.g., file/database drivers),
     * this will call Cache::flush() which clears the ENTIRE cache store,
     * including sessions, resources, ROR data, and any other cached data.
     */
    public function invalidateAllVocabularyCaches(): void
    {
        if ($this->supportsTagging()) {
            Cache::tags(['vocabularies'])->flush();
        } else {
            // Log warning before clearing entire cache store
            Log::warning('Cache tagging not supported. Clearing entire cache store for vocabulary invalidation.');
            Cache::flush();
        }
    }

    /**
     * Invalidate a specific vocabulary cache.
     *
     * @param  CacheKey  $key  The vocabulary cache key
     */
    public function invalidateVocabularyCache(CacheKey $key): void
    {
        if ($this->supportsTagging()) {
            Cache::tags($key->tags())->forget($key->key());
        } else {
            Cache::forget($key->key());
        }
    }

    /**
     * Extend the TTL of a vocabulary cache without re-fetching the value.
     *
     * Uses the same cache repository as writes (tagged when supported)
     * to ensure the correct key namespace is used.
     *
     * @param  CacheKey  $key  The vocabulary cache key
     * @return bool True if the key existed and TTL was extended
     */
    public function touchVocabularyCache(CacheKey $key): bool
    {
        return $this->getCacheInstance($key->tags())->touch($key->key(), $key->ttl());
    }

    /**
     * Extend TTLs for all vocabulary caches that currently exist.
     *
     * @return array<string, bool> Map of cache key value → touch result
     */
    public function touchAllVocabularyCaches(): array
    {
        $results = [];

        foreach (CacheKey::vocabularyKeys() as $key) {
            $results[$key->value] = $this->touchVocabularyCache($key);
        }

        return $results;
    }
}
