<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Facades\Cache;

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
    case RESOURCE_LISTING_COUNT = 'resources:listing_count';
    case RESOURCE_FILTER_OPTIONS = 'resources:filter_options';
    case IGSN_LISTING_COUNT = 'igsns:listing_count';
    case DASHBOARD_METRICS = 'dashboard:metrics';

    // Vocabulary cache keys
    case GCMD_SCIENCE_KEYWORDS = 'vocabularies:gcmd:science_keywords';
    case GCMD_INSTRUMENTS = 'vocabularies:gcmd:instruments';
    case GCMD_PLATFORMS = 'vocabularies:gcmd:platforms';
    case GCMD_PROVIDERS = 'vocabularies:gcmd:providers';
    case MSL_KEYWORDS = 'vocabularies:msl:keywords';
    case MSL_LABORATORIES = 'vocabularies:msl:laboratories';
    case PID4INST_INSTRUMENTS = 'vocabularies:pid4inst:instruments';
    case RAID_PROJECTS = 'vocabularies:raid:projects';
    case CHRONOSTRAT_TIMESCALE = 'vocabularies:chronostrat:timescale';
    case GEMET_THESAURUS = 'vocabularies:gemet:thesaurus';
    case ANALYTICAL_METHODS = 'vocabularies:analytical_methods';
    case EUROSCIVOC = 'vocabularies:euroscivoc';
    case CGI_SIMPLE_LITHOLOGY = 'vocabularies:cgi:simple_lithology';

    // ROR affiliation cache keys
    case ROR_AFFILIATION = 'ror:affiliation';

    // ORCID cache keys
    case ORCID_PERSON = 'orcid:person';

    // Editor settings cache key
    case DOCS_EDITOR_SETTINGS = 'docs:editor_settings';

    // Portal cache keys
    case PORTAL_FREE_KEYWORD_SUGGESTIONS = 'portal:free_keyword_suggestions';
    case PORTAL_KEYWORD_SUGGESTIONS = 'portal:keyword_suggestions';
    case PORTAL_THESAURUS_FACETS = 'portal:thesaurus_facets';
    case PORTAL_THESAURUS_SUBJECT_INDEX = 'portal:thesaurus_subject_index';
    case PORTAL_TEMPORAL_RANGE = 'portal:temporal_range';
    case PORTAL_RESOURCE_TYPE_FACETS = 'portal:resource_type_facets';
    case PORTAL_DATACENTER_FACETS = 'portal:datacenter_facets';
    case PORTAL_IGSN_FACETS = 'portal:igsn_facets';
    case PORTAL_PAGE_PAYLOAD = 'portal:page_payload';
    case PORTAL_LISTING_COUNT = 'portal:listing_count';
    case PORTAL_MAP_PAYLOAD = 'portal:map_payload';
    case PORTAL_MAP_EXTENT = 'portal:map_extent';

    // DOI citation cache keys
    case DOI_CITATION = 'doi:citation';

    // DataCite REST API metadata cache keys
    case DOI_DATACITE_METADATA = 'doi:datacite_metadata';

    // Citation lookup (Crossref → DataCite fallback)
    case CITATION_LOOKUP = 'citations:lookup';

    // Cache statistics
    case CACHE_STATS = 'system:cache_stats';

    // Assistance suggestion counts
    case ASSISTANCE_TOTAL_PENDING_COUNT = 'assistance:total_pending_count';

    // Assessment summary metrics
    case ASSESSMENT_AVERAGE_SUMMARY = 'assessment:average_summary';

    // F-UJI health check result (short-lived)
    case FUJI_HEALTH_STATUS = 'assessment:fuji_health_status';

    // Published landing page render payloads
    case LANDING_PAGE_RENDER_DATA = 'landing_pages:render_data:v6';

    // Landing page setup modal download URL suggestions
    case LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS = 'landing-page.download-url-suggestions';

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
            self::RESOURCE_LISTING_COUNT,
            self::IGSN_LISTING_COUNT => (int) config('listing_performance.internal_count_ttl', 300),
            self::RESOURCE_FILTER_OPTIONS => 300,
            self::DASHBOARD_METRICS => 300,

            // Individual resources - 15 minutes
            self::RESOURCE_DETAIL => 900,

            // Vocabularies rarely change - 24 hours
            self::GCMD_SCIENCE_KEYWORDS,
            self::GCMD_INSTRUMENTS,
            self::GCMD_PLATFORMS,
            self::GCMD_PROVIDERS,
            self::MSL_KEYWORDS,
            self::MSL_LABORATORIES,
            self::PID4INST_INSTRUMENTS,
            self::RAID_PROJECTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS,
            self::ANALYTICAL_METHODS,
            self::EUROSCIVOC => 86400,
            self::CGI_SIMPLE_LITHOLOGY => 86400,

            // ROR affiliations are relatively stable - 7 days
            self::ROR_AFFILIATION => 604800,

            // ORCID person data - 24 hours
            self::ORCID_PERSON => 86400,

            // Editor settings for docs - 1 hour (settings rarely change)
            self::DOCS_EDITOR_SETTINGS => 3600,

            // Portal keyword facets - 1 hour
            self::PORTAL_FREE_KEYWORD_SUGGESTIONS,
            self::PORTAL_KEYWORD_SUGGESTIONS,
            self::PORTAL_THESAURUS_FACETS,
            self::PORTAL_THESAURUS_SUBJECT_INDEX => 3600,

            // Portal temporal range - 1 hour (year boundaries change infrequently)
            self::PORTAL_TEMPORAL_RANGE => 3600,

            // Portal resource type facets - 10 minutes
            self::PORTAL_RESOURCE_TYPE_FACETS,
            self::PORTAL_DATACENTER_FACETS,
            self::PORTAL_IGSN_FACETS => 600,

            // Portal Inertia payloads - very short-lived to absorb crawler bursts
            self::PORTAL_PAGE_PAYLOAD => max(0, (int) config('bot_protection.portal_cache_ttl', 120)),
            self::PORTAL_LISTING_COUNT => (int) config('listing_performance.portal_count_ttl', 120),
            self::PORTAL_MAP_PAYLOAD => max(0, (int) config('portal_map.cache_ttl', 30)),
            self::PORTAL_MAP_EXTENT => max(0, (int) config('portal_map.extent_cache_ttl', 300)),

            // DOI citations and DataCite metadata are relatively stable - 24 hours
            self::DOI_CITATION, self::DOI_DATACITE_METADATA, self::CITATION_LOOKUP => 86400,

            // Cache statistics - 5 minutes
            self::CACHE_STATS => 300,

            // Assistance total pending count - 2 minutes (changes after discovery jobs)
            self::ASSISTANCE_TOTAL_PENDING_COUNT => 120,

            // Assessment average summary - 2 minutes (invalidated on assessment save/delete)
            self::ASSESSMENT_AVERAGE_SUMMARY => 120,

            // F-UJI health check result - 30 seconds (short-lived to reflect quick recovery)
            self::FUJI_HEALTH_STATUS => 30,

            // Published landing page render data - short-lived and configurable
            self::LANDING_PAGE_RENDER_DATA => max(0, (int) config('bot_protection.landing_cache_ttl', 600)),

            // Download URL suggestions use rememberForever and explicit invalidation.
            // This TTL acts only as a safe default if the enum is reused elsewhere.
            self::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS => 86400,
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

            self::RESOURCE_LISTING_COUNT,
            self::IGSN_LISTING_COUNT => ['resources', 'internal_listing_counts'],
            self::RESOURCE_FILTER_OPTIONS => ['resources', 'resource_filter_options'],
            self::DASHBOARD_METRICS => ['resources', 'dashboard_metrics'],

            self::GCMD_SCIENCE_KEYWORDS,
            self::GCMD_INSTRUMENTS,
            self::GCMD_PLATFORMS,
            self::GCMD_PROVIDERS,
            self::MSL_KEYWORDS,
            self::MSL_LABORATORIES,
            self::PID4INST_INSTRUMENTS,
            self::RAID_PROJECTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS,
            self::ANALYTICAL_METHODS,
            self::EUROSCIVOC => ['vocabularies'],
            self::CGI_SIMPLE_LITHOLOGY => ['vocabularies'],

            self::ROR_AFFILIATION => ['ror', 'affiliations'],

            self::ORCID_PERSON => ['orcid'],

            self::DOCS_EDITOR_SETTINGS => ['settings', 'docs'],

            self::PORTAL_FREE_KEYWORD_SUGGESTIONS,
            self::PORTAL_KEYWORD_SUGGESTIONS => ['portal', 'keywords'],

            self::PORTAL_THESAURUS_FACETS,
            self::PORTAL_THESAURUS_SUBJECT_INDEX => ['portal', 'thesauri', 'vocabularies'],

            self::PORTAL_TEMPORAL_RANGE => ['portal', 'temporal'],

            self::PORTAL_RESOURCE_TYPE_FACETS => ['portal', 'resource_types'],

            self::PORTAL_DATACENTER_FACETS => ['portal', 'datacenters'],

            self::PORTAL_IGSN_FACETS => ['portal', 'igsn_facets'],

            self::PORTAL_LISTING_COUNT => ['portal_listing_counts'],

            self::PORTAL_PAGE_PAYLOAD => ['portal_page_payloads'],
            self::PORTAL_MAP_PAYLOAD => ['portal_map_payloads'],
            self::PORTAL_MAP_EXTENT => ['portal_map_extents'],

            self::DOI_CITATION => ['doi', 'citations'],

            self::DOI_DATACITE_METADATA => ['doi', 'datacite_metadata'],

            self::CITATION_LOOKUP => ['doi', 'citations'],

            self::CACHE_STATS => ['system'],

            self::ASSISTANCE_TOTAL_PENDING_COUNT => ['assistance'],

            self::ASSESSMENT_AVERAGE_SUMMARY => ['assessments'],

            self::FUJI_HEALTH_STATUS => ['assessments'],

            self::LANDING_PAGE_RENDER_DATA => ['resources', 'landing_pages'],

            self::LANDING_PAGE_DOWNLOAD_URL_SUGGESTIONS => [],
        };
    }

    /**
     * Forget this cache key, using tag-aware invalidation when the store supports tagging.
     *
     * This ensures that entries written via a tagged cache repository
     * (e.g. Cache::tags([...])->remember(...)) are properly cleared on
     * Redis/Memcached where Cache::forget() alone would not work.
     *
     * @param  string|int|null  $suffix  Optional key suffix (same as key())
     */
    public function forget(string|int|null $suffix = null): bool
    {
        $fullKey = $this->key($suffix);
        $tags = $this->tags();

        if ($tags !== [] && method_exists(Cache::getStore(), 'tags')) {
            return Cache::tags($tags)->forget($fullKey);
        }

        return Cache::forget($fullKey);
    }

    /**
     * Forget the unscoped and both public-portal variants of this key.
     */
    public function forgetPortalVariants(): void
    {
        $this->forget();

        foreach (PortalScope::cases() as $scope) {
            $this->forget($scope->value);
        }
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
            self::MSL_LABORATORIES,
            self::PID4INST_INSTRUMENTS,
            self::RAID_PROJECTS,
            self::CHRONOSTRAT_TIMESCALE,
            self::GEMET_THESAURUS,
            self::ANALYTICAL_METHODS,
            self::EUROSCIVOC,
            self::CGI_SIMPLE_LITHOLOGY,
        ];
    }
}
