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
     * @param \Closure(): ?array<int|string, mixed> $callback Callback to load keywords
     * @return array<int|string, mixed>|null
     */
    public function cacheGcmdScienceKeywords(\Closure $callback): ?array
    {
        return $this->cacheVocabulary(CacheKey::GCMD_SCIENCE_KEYWORDS, $callback);
    }

    /**
     * Cache GCMD instruments.
     *
     * @param \Closure(): ?array<int|string, mixed> $callback Callback to load instruments
     * @return array<int|string, mixed>|null
     */
    public function cacheGcmdInstruments(\Closure $callback): ?array
    {
        return $this->cacheVocabulary(CacheKey::GCMD_INSTRUMENTS, $callback);
    }

    /**
     * Cache GCMD platforms.
     *
     * @param \Closure(): ?array<int|string, mixed> $callback Callback to load platforms
     * @return array<int|string, mixed>|null
     */
    public function cacheGcmdPlatforms(\Closure $callback): ?array
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PLATFORMS, $callback);
    }

    /**
     * Cache GCMD providers.
     *
     * @param \Closure(): ?array<int|string, mixed> $callback Callback to load providers
     * @return array<int|string, mixed>|null
     */
    public function cacheGcmdProviders(\Closure $callback): ?array
    {
        return $this->cacheVocabulary(CacheKey::GCMD_PROVIDERS, $callback);
    }

    /**
     * Cache MSL keywords.
     *
     * @param \Closure(): ?array<int|string, mixed> $callback Callback to load keywords
     * @return array<int|string, mixed>|null
     */
    public function cacheMslKeywords(\Closure $callback): ?array
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
