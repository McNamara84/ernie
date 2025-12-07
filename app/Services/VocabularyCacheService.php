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
     * Check if the current cache store supports tagging.
     */
    private function supportsTagging(): bool
    {
        return method_exists(Cache::getStore(), 'tags');
    }

    /**
     * Get cache instance with tags if supported, otherwise without tags.
     *
     * @param array<int, string> $tags
     * @return \Illuminate\Contracts\Cache\Repository
     */
    private function getCacheInstance(array $tags): \Illuminate\Contracts\Cache\Repository
    {
        if ($this->supportsTagging()) {
            return Cache::tags($tags);
        }

        return Cache::store();
    }

    /**
     * Cache GCMD science keywords.
     *
     * @template TValue
     * @param \Closure(): TValue $callback Callback to load keywords
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
     * @param \Closure(): TValue $callback Callback to load instruments
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
     * @param \Closure(): TValue $callback Callback to load platforms
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
     * @param \Closure(): TValue $callback Callback to load providers
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
     * @param \Closure(): TValue $callback Callback to load keywords
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
     * @param CacheKey $key Cache key enum
     * @param \Closure(): TValue $callback Callback to load vocabulary data
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
     *
     * @return void
     */
    public function invalidateAllVocabularyCaches(): void
    {
        if ($this->supportsTagging()) {
            Cache::tags(['vocabularies'])->flush();
        } else {
            // WARNING: This clears the ENTIRE cache store, not just vocabularies
            Cache::flush();
        }
    }

    /**
     * Invalidate a specific vocabulary cache.
     *
     * @param CacheKey $key The vocabulary cache key
     * @return void
     */
    public function invalidateVocabularyCache(CacheKey $key): void
    {
        if ($this->supportsTagging()) {
            Cache::tags($key->tags())->forget($key->key());
        } else {
            Cache::forget($key->key());
        }
    }
}
