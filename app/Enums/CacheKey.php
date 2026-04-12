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
    case PID4INST_INSTRUMENTS = 'vocabularies:pid4inst:instruments';
    case CHRONOSTRAT_TIMESCALE = 'vocabularies:chronostrat:timescale';
    case GEMET_THESAURUS = 'vocabularies:gemet:thesaurus';

    // ROR affiliation cache keys
    case ROR_AFFILIATION = 'ror:affiliation';

    // ORCID cache keys
    case ORCID_PERSON = 'orcid:person';

    // Editor settings cache key
    case DOCS_EDITOR_SETTINGS = 'docs:editor_settings';

    // Portal cache keys
    case PORTAL_KEYWORD_SUGGESTIONS = 'portal:keyword_suggestions';
    case PORTAL_TEMPORAL_RANGE = 'portal:temporal_range';
    case PORTAL_RESOURCE_TYPE_FACETS = 'portal:resource_type_facets';
    case PORTAL_DATACENTER_FACETS = 'portal:datacenter_facets';

    // DOI citation cache keys
    case DOI_CITATION = 'doi:citation';

    // DataCite REST API metadata cache keys
    case DOI_DATACITE_METADATA = 'doi:datacite_metadata';

    // Cache statistics
    case CACHE_STATS = 'system:cache_stats';

    // Assistance suggestion counts
    case SUGGESTED_RELATIONS_COUNT = 'assistance:suggested_relations_count';
    case SUGGESTED_ORCIDS_COUNT = 'assistance:suggested_orcids_count';
    case SUGGESTED_RORS_COUNT = 'assistance:suggested_rors_count';
    case ASSISTANCE_TOTAL_PENDING_COUNT = 'assistance:total_pending_count';

    // Landing page Schema.org JSON-LD
    case SCHEMA_ORG_JSONLD = 'landing_pages:schema_org_jsonld';

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
            self::MSL_KEYWORDS,
            self::PID4INST_INSTRUMENTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS => 86400,

            // ROR affiliations are relatively stable - 7 days
            self::ROR_AFFILIATION => 604800,

            // ORCID person data - 24 hours
            self::ORCID_PERSON => 86400,

            // Editor settings for docs - 1 hour (settings rarely change)
            self::DOCS_EDITOR_SETTINGS => 3600,

            // Portal keyword suggestions - 1 hour
            self::PORTAL_KEYWORD_SUGGESTIONS => 3600,

            // Portal temporal range - 1 hour (year boundaries change infrequently)
            self::PORTAL_TEMPORAL_RANGE => 3600,

            // Portal resource type facets - 10 minutes
            self::PORTAL_RESOURCE_TYPE_FACETS,
            self::PORTAL_DATACENTER_FACETS => 600,

            // DOI citations and DataCite metadata are relatively stable - 24 hours
            self::DOI_CITATION, self::DOI_DATACITE_METADATA => 86400,

            // Cache statistics - 5 minutes
            self::CACHE_STATS => 300,

            // Suggested relations/orcids/rors count - 2 minutes (changes after discovery jobs)
            self::SUGGESTED_RELATIONS_COUNT,
            self::SUGGESTED_ORCIDS_COUNT,
            self::SUGGESTED_RORS_COUNT,
            self::ASSISTANCE_TOTAL_PENDING_COUNT => 120,

            // Schema.org JSON-LD - 1 hour (invalidated by ResourceObserver on update)
            self::SCHEMA_ORG_JSONLD => 3600,
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
            self::MSL_KEYWORDS,
            self::PID4INST_INSTRUMENTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS => ['vocabularies'],

            self::ROR_AFFILIATION => ['ror', 'affiliations'],

            self::ORCID_PERSON => ['orcid'],

            self::DOCS_EDITOR_SETTINGS => ['settings', 'docs'],

            self::PORTAL_KEYWORD_SUGGESTIONS => ['portal', 'keywords'],

            self::PORTAL_TEMPORAL_RANGE => ['portal', 'temporal'],

            self::PORTAL_RESOURCE_TYPE_FACETS => ['portal', 'resource_types'],

            self::PORTAL_DATACENTER_FACETS => ['portal', 'datacenters'],

            self::DOI_CITATION => ['doi', 'citations'],

            self::DOI_DATACITE_METADATA => ['doi', 'datacite_metadata'],

            self::CACHE_STATS => ['system'],

            self::SUGGESTED_RELATIONS_COUNT,
            self::SUGGESTED_ORCIDS_COUNT,
            self::SUGGESTED_RORS_COUNT,
            self::ASSISTANCE_TOTAL_PENDING_COUNT => ['assistance'],

            self::SCHEMA_ORG_JSONLD => ['resources', 'landing_pages'],
        };
    }

    /**
     * Get all vocabulary-related cache keys.
     *
     * @return array<int, self>
     */
    public static function vocabularyKeys(): array
    {
        return [
            self::GCMD_SCIENCE_KEYWORDS,
            self::GCMD_INSTRUMENTS,
            self::GCMD_PLATFORMS,
            self::GCMD_PROVIDERS,
            self::MSL_KEYWORDS,
            self::PID4INST_INSTRUMENTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS,
        ];
    }
}
