<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Centralized cache key management for the application.
 *
 * This enum provides standardized cache key patterns and TTL values
 * to ensure consistency across the application and prevent cache key conflicts.
 */
enum CacheKey: string
{
    // Resource-related cache keys
    case RESOURCE_LIST = 'resources:list';
    case RESOURCE_DETAIL = 'resources:detail';
    case RESOURCE_COUNT = 'resources:count';

    // Vocabulary cache keys
    case GCMD_SCIENCE_KEYWORDS = 'vocabularies:gcmd:science_keywords';
    case GCMD_INSTRUMENTS = 'vocabularies:gcmd:instruments';
    case GCMD_PLATFORMS = 'vocabularies:gcmd:platforms';
    case GCMD_PROVIDERS = 'vocabularies:gcmd:providers';
    case MSL_KEYWORDS = 'vocabularies:msl:keywords';

    // ROR affiliation cache keys
    case ROR_AFFILIATION = 'ror:affiliation';

    // ORCID cache keys
    case ORCID_PERSON = 'orcid:person';

    // Editor settings cache key
    case DOCS_EDITOR_SETTINGS = 'docs:editor_settings';

    // Cache statistics
    case CACHE_STATS = 'system:cache_stats';

    /**
     * Get the full cache key with optional suffix.
     *
     * @param  string|int|null  $suffix  Additional identifier (e.g., resource ID, user ID)
     * @return string The complete cache key
     */
    public function key(string|int|null $suffix = null): string
    {
        $baseKey = $this->value;

        if ($suffix !== null) {
            return "{$baseKey}:{$suffix}";
        }

        return $baseKey;
    }

    /**
     * Get the TTL (time-to-live) for this cache key in seconds.
     *
     * @return int The TTL in seconds
     */
    public function ttl(): int
    {
        return match ($this) {
            // Resource listings change frequently - 5 minutes
            self::RESOURCE_LIST, self::RESOURCE_COUNT => 300,

            // Individual resources - 15 minutes
            self::RESOURCE_DETAIL => 900,

            // Vocabularies rarely change - 24 hours
            self::GCMD_SCIENCE_KEYWORDS,
            self::GCMD_INSTRUMENTS,
            self::GCMD_PLATFORMS,
            self::GCMD_PROVIDERS,
            self::MSL_KEYWORDS => 86400,

            // ROR affiliations are relatively stable - 7 days
            self::ROR_AFFILIATION => 604800,

            // ORCID person data - 24 hours
            self::ORCID_PERSON => 86400,

            // Editor settings for docs - 1 hour (settings rarely change)
            self::DOCS_EDITOR_SETTINGS => 3600,

            // Cache statistics - 5 minutes
            self::CACHE_STATS => 300,
        };
    }

    /**
     * Get cache tags for this key to enable tag-based invalidation.
     *
     * @return array<string> Array of cache tags
     */
    public function tags(): array
    {
        return match ($this) {
            self::RESOURCE_LIST,
            self::RESOURCE_DETAIL,
            self::RESOURCE_COUNT => ['resources'],

            self::GCMD_SCIENCE_KEYWORDS,
            self::GCMD_INSTRUMENTS,
            self::GCMD_PLATFORMS,
            self::GCMD_PROVIDERS,
            self::MSL_KEYWORDS => ['vocabularies'],

            self::ROR_AFFILIATION => ['ror', 'affiliations'],

            self::ORCID_PERSON => ['orcid'],

            self::DOCS_EDITOR_SETTINGS => ['settings', 'docs'],

            self::CACHE_STATS => ['system'],
        };
    }
}
