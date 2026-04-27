<?php

declare(strict_types=1);

namespace App\Services\Citations;

use App\Enums\CacheKey;
use App\Services\DataCiteApiService;
use App\Support\Traits\ChecksCacheTagging;

/**
 * Orchestrates DOI metadata lookup for the Citation Manager. Per project
 * decision the primary source is Crossref (better coverage of journal
 * articles and books), falling back to DataCite.
 */
class CitationLookupService
{
    use ChecksCacheTagging;

    public function __construct(
        private readonly CrossrefClient $crossref,
        private readonly DataCiteApiService $datacite,
        private readonly DataCiteTypeMapper $dataciteTypeMapper,
    ) {}

    public function lookup(string $doi): CitationLookupResult
    {
        // Reuse the shared DOI normalization (resolver-URL stripping +
        // lowercasing) so resolver URLs and bare DOIs share a single cache
        // entry, matching DataCiteApiService behavior.
        $normalized = $this->datacite->normalizeDoi($doi);
        if ($normalized === null) {
            return CitationLookupResult::notFound('crossref');
        }

        $cacheKey = CacheKey::CITATION_LOOKUP->key($normalized);
        $cache = $this->getCacheInstance(CacheKey::CITATION_LOOKUP->tags());

        // Honour the operator-configurable `CROSSREF_CACHE_TTL` (env →
        // `config/crossref.php`) so deployments can tune how long lookups are
        // cached without code changes; fall back to the enum default.
        $ttl = (int) config('crossref.cache_ttl', CacheKey::CITATION_LOOKUP->ttl());

        /** @var CitationLookupResult|null $cached */
        $cached = $cache->get($cacheKey);
        if ($cached instanceof CitationLookupResult) {
            return $cached;
        }

        $primary = $this->crossref->lookup($normalized);
        $primaryErrored = $primary->error !== null;

        $result = ($primaryErrored || ! $primary->found)
            ? $this->lookupDataCite($normalized)
            : $primary;

        // If the primary (Crossref) errored and DataCite did not produce a
        // confirming hit, surface the original error to the caller instead
        // of masquerading it as `not_found`. This lets the controller map
        // it to a 502 so the UI can show a transient-failure message.
        if ($primaryErrored && ! $result->found) {
            return CitationLookupResult::error($primary->source, (string) $primary->error);
        }

        // Cache policy:
        //   - Successful hits: always cache.
        //   - `not_found`: only cache when the primary lookup completed
        //     without errors. Otherwise a transient Crossref outage would
        //     persist a DataCite `not_found` for a DOI that does exist in
        //     Crossref, locking users out for the full TTL.
        //   - Errors: never cache (transient upstream failure).
        $shouldCache = $result->error === null
            && ($result->found || ! $primaryErrored);

        if ($shouldCache) {
            $cache->put($cacheKey, $result, $ttl);
        }

        return $result;
    }

    private function lookupDataCite(string $doi): CitationLookupResult
    {
        $attributes = $this->datacite->getDataCiteMetadata($doi);
        if ($attributes === null) {
            return CitationLookupResult::notFound('datacite');
        }

        return CitationLookupResult::hit('datacite', $this->transform($attributes, $doi));
    }

    /**
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    private function transform(array $attrs, string $doi): array
    {
        $titles = [];
        foreach ((array) ($attrs['titles'] ?? []) as $t) {
            if (!is_array($t) || !isset($t['title']) || !is_string($t['title'])) {
                continue;
            }
            $titles[] = [
                'title' => trim($t['title']),
                'titleType' => isset($t['titleType']) && is_string($t['titleType'])
                    ? $t['titleType']
                    : 'MainTitle',
            ];
        }

        $creators = [];
        foreach ((array) ($attrs['creators'] ?? []) as $creator) {
            if (!is_array($creator)) {
                continue;
            }

            $nameType = isset($creator['nameType']) && is_string($creator['nameType'])
                ? $creator['nameType']
                : 'Personal';

            $identifier = null;
            $scheme = null;
            foreach ((array) ($creator['nameIdentifiers'] ?? []) as $ni) {
                if (is_array($ni) && isset($ni['nameIdentifier']) && is_string($ni['nameIdentifier'])) {
                    $identifier = $ni['nameIdentifier'];
                    $scheme = isset($ni['nameIdentifierScheme']) && is_string($ni['nameIdentifierScheme'])
                        ? $ni['nameIdentifierScheme']
                        : null;
                    break;
                }
            }

            $affiliations = [];
            foreach ((array) ($creator['affiliation'] ?? []) as $aff) {
                if (is_string($aff)) {
                    $affiliations[] = ['name' => $aff, 'affiliationIdentifier' => null, 'scheme' => null];
                } elseif (is_array($aff) && isset($aff['name']) && is_string($aff['name'])) {
                    $affiliations[] = [
                        'name' => $aff['name'],
                        'affiliationIdentifier' => isset($aff['affiliationIdentifier']) && is_string($aff['affiliationIdentifier'])
                            ? $aff['affiliationIdentifier']
                            : null,
                        'scheme' => isset($aff['affiliationIdentifierScheme']) && is_string($aff['affiliationIdentifierScheme'])
                            ? $aff['affiliationIdentifierScheme']
                            : null,
                    ];
                }
            }

            $creators[] = [
                'nameType' => $nameType,
                'name' => isset($creator['name']) && is_string($creator['name']) ? $creator['name'] : '',
                'givenName' => isset($creator['givenName']) && is_string($creator['givenName']) ? $creator['givenName'] : null,
                'familyName' => isset($creator['familyName']) && is_string($creator['familyName']) ? $creator['familyName'] : null,
                'nameIdentifier' => $identifier,
                'nameIdentifierScheme' => $scheme,
                'affiliations' => $affiliations,
            ];
        }

        return [
            'relatedItemType' => $this->dataciteTypeMapper->map(
                isset($attrs['types']['resourceTypeGeneral']) && is_string($attrs['types']['resourceTypeGeneral'])
                    ? $attrs['types']['resourceTypeGeneral']
                    : null
            ),
            'titles' => $titles,
            'creators' => $creators,
            'publicationYear' => isset($attrs['publicationYear']) ? (int) $attrs['publicationYear'] : null,
            'volume' => null,
            'issue' => null,
            'firstPage' => null,
            'lastPage' => null,
            'publisher' => isset($attrs['publisher']) && is_string($attrs['publisher']) ? $attrs['publisher'] : null,
            'identifier' => $doi,
            'identifierType' => 'DOI',
            'additionalIdentifiers' => [],
        ];
    }
}
