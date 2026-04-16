<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Models\ThesaurusSetting;
use App\Support\AnalyticalMethodsVocabularyParser;
use App\Support\ChronostratVocabularyParser;
use App\Support\GcmdVocabularyParser;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Service for checking thesaurus status and comparing with remote APIs.
 *
 * Supports NASA KMS API (for GCMD vocabularies) and ARDC Linked Data API
 * (for ICS Chronostratigraphic Timescale).
 */
class ThesaurusStatusService
{
    use ChecksCacheTagging;
    private const NASA_KMS_BASE_URL = 'https://cmr.earthdata.nasa.gov/kms/concepts/concept_scheme/';

    /**
     * Get the local status of a thesaurus (from stored JSON file).
     *
     * @return array{exists: bool, conceptCount: int, lastUpdated: string|null}
     */
    public function getLocalStatus(ThesaurusSetting $thesaurus): array
    {
        $filePath = $thesaurus->getFilePath();

        if (! Storage::exists($filePath)) {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        $content = Storage::get($filePath);

        // Storage::get() can return null in edge cases (e.g., file deleted between exists() and get())
        // Handle both null and empty string cases
        if ($content === null || $content === '') {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        /** @var array{lastUpdated?: string, data?: array<int, array<string, mixed>>}|null $data */
        $data = json_decode($content, true);

        if ($data === null) {
            return [
                'exists' => false,
                'conceptCount' => 0,
                'lastUpdated' => null,
            ];
        }

        return [
            'exists' => true,
            'conceptCount' => $this->countConcepts($data['data'] ?? []),
            'lastUpdated' => $data['lastUpdated'] ?? null,
        ];
    }

    /**
     * Get the concept count from the remote API.
     *
     * For GCMD thesauri, queries NASA KMS API.
     * For Chronostratigraphy and Analytical Methods, queries ARDC Linked Data API.
     * For GEMET, queries the EIONET REST API.
     *
     * @throws \RuntimeException If the API request fails
     */
    public function getRemoteConceptCount(ThesaurusSetting $thesaurus): int
    {
        if ($thesaurus->isGcmd()) {
            return $this->getGcmdRemoteCount($thesaurus);
        }

        if ($thesaurus->type === ThesaurusSetting::TYPE_CHRONOSTRAT) {
            return $this->getChronostratRemoteCount();
        }

        if ($thesaurus->type === ThesaurusSetting::TYPE_GEMET) {
            return $this->getGemetRemoteCount();
        }

        if ($thesaurus->type === ThesaurusSetting::TYPE_ANALYTICAL_METHODS) {
            return $this->getAnalyticalMethodsRemoteCount($thesaurus);
        }

        if ($thesaurus->type === ThesaurusSetting::TYPE_EUROSCIVOC) {
            return $this->getEuroSciVocRemoteCount();
        }

        throw new \RuntimeException("Unsupported thesaurus type for remote check: {$thesaurus->type}");
    }

    /**
     * Get concept count from NASA KMS API.
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getGcmdRemoteCount(ThesaurusSetting $thesaurus): int
    {
        $vocabularyType = $thesaurus->getVocabularyType();
        $url = self::NASA_KMS_BASE_URL.$vocabularyType.'?format=rdf&page_num=1&page_size=1';

        $response = Http::timeout(30)
            ->accept('application/rdf+xml')
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Failed to fetch from NASA KMS API: HTTP {$response->status()}"
            );
        }

        $parser = new GcmdVocabularyParser;

        return $parser->extractTotalHits($response->body());
    }

    /**
     * Get concept count from ARDC Linked Data API for Chronostratigraphy.
     *
     * Fetches all items via ArdcApiService and counts concepts after filtering
     * out boundary/GSSP entries, matching the same filter logic used by
     * ChronostratVocabularyParser::extractConcepts().
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getChronostratRemoteCount(): int
    {
        $ardcApi = new ArdcApiService(
            (string) config('ardc.chronostratigraphy.url')
        );
        $allItems = $ardcApi->fetchAllItems(timeout: 30);

        $parser = new ChronostratVocabularyParser;

        return count($parser->extractConcepts($allItems));
    }

    /**
     * Get concept count from ARDC Linked Data API for Analytical Methods.
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getAnalyticalMethodsRemoteCount(ThesaurusSetting $thesaurus): int
    {
        $version = $thesaurus->version ?? (string) config('ardc.analytical_methods.default_version');
        $urlTemplate = (string) config('ardc.analytical_methods.url_template');
        $baseUrl = str_replace('{version}', $version, $urlTemplate);

        $ardcApi = new ArdcApiService($baseUrl);
        $allItems = $ardcApi->fetchAllItems(timeout: 30);

        $parser = new AnalyticalMethodsVocabularyParser;

        return count($parser->extractConcepts($allItems));
    }

    /**
     * Compare local and remote concept counts.
     *
     * An update is considered available when the remote concept count is greater
     * than the local count. This indicates new concepts have been added to the
     * NASA GCMD thesaurus. We don't trigger updates when remote < local because
     * concept deletions in NASA's vocabulary are rare and may indicate API issues.
     *
     * @return array{localCount: int, remoteCount: int, updateAvailable: bool, lastUpdated: string|null}
     *
     * @throws \RuntimeException If the API request fails
     */
    public function compareWithRemote(ThesaurusSetting $thesaurus): array
    {
        $localStatus = $this->getLocalStatus($thesaurus);
        $remoteCount = $this->getRemoteConceptCount($thesaurus);

        return [
            'localCount' => $localStatus['conceptCount'],
            'remoteCount' => $remoteCount,
            'updateAvailable' => $remoteCount > $localStatus['conceptCount'],
            'lastUpdated' => $localStatus['lastUpdated'],
        ];
    }

    /**
     * Get total node count from GEMET REST API.
     *
     * Counts SuperGroups + Groups + concept assignments per group to match
     * the local hierarchy counting method used by {@see countConcepts()},
     * which counts every node in the tree (including concepts appearing
     * in multiple groups).
     *
     * Uses CacheKey-based caching (1 hour TTL) to avoid 36+ HTTP requests per
     * status check. The cache is invalidated via `cache:clear-app vocabularies`.
     *
     * @throws \RuntimeException If the API request fails
     */
    private function getGemetRemoteCount(): int
    {
        $cacheKey = CacheKey::GEMET_THESAURUS->key('remote_count');
        $cache = $this->supportsTagging()
            ? Cache::tags(CacheKey::GEMET_THESAURUS->tags())
            : Cache::store();

        /** @var int */
        return $cache->remember($cacheKey, 3600, function (): int {
            $gemetApi = new GemetApiService;
            $superGroups = $gemetApi->fetchSuperGroups(timeout: 30);
            $groups = $gemetApi->fetchGroups(timeout: 30);

            $conceptCounts = $gemetApi->fetchConceptCountsByGroup($groups, timeout: 30);
            $conceptCount = array_sum($conceptCounts);

            return count($superGroups) + count($groups) + $conceptCount;
        });
    }

    /**
     * Get concept count from the EU Publications Office SPARQL endpoint for EuroSciVoc.
     *
     * Uses a SPARQL COUNT query against the public endpoint to determine the
     * current number of concepts. Uses caching (1 hour TTL) to avoid repeated
     * SPARQL queries per status check.
     *
     * @throws \RuntimeException If the SPARQL request fails
     */
    private function getEuroSciVocRemoteCount(): int
    {
        $cacheKey = CacheKey::EUROSCIVOC->key('remote_count');
        $cache = $this->supportsTagging()
            ? Cache::tags(CacheKey::EUROSCIVOC->tags())
            : Cache::store();

        /** @var int */
        return $cache->remember($cacheKey, 3600, function (): int {
            $conceptSchemeUri = (string) config('euroscivoc.concept_scheme_uri');
            $sparqlEndpoint = 'https://publications.europa.eu/webapi/rdf/sparql';

            $query = <<<SPARQL
                PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
                SELECT (COUNT(DISTINCT ?concept) AS ?count) WHERE {
                    ?concept a skos:Concept .
                    ?concept skos:inScheme <{$conceptSchemeUri}> .
                }
                SPARQL;

            $response = Http::timeout(30)
                ->accept('application/sparql-results+json')
                ->get($sparqlEndpoint, [
                    'query' => $query,
                    'format' => 'application/sparql-results+json',
                ]);

            if (! $response->successful()) {
                throw new \RuntimeException(
                    "Failed to query EuroSciVoc SPARQL endpoint: HTTP {$response->status()}"
                );
            }

            $data = $response->json();
            $countValue = $data['results']['bindings'][0]['count']['value'] ?? null;

            if ($countValue === null) {
                throw new \RuntimeException(
                    'Unexpected EuroSciVoc SPARQL response format: missing results.bindings[0].count.value'
                );
            }

            return (int) $countValue;
        });
    }

    /**
     * Recursively count all concepts in the hierarchy.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    private function countConcepts(array $data): int
    {
        $count = count($data);

        foreach ($data as $item) {
            if (isset($item['children']) && is_array($item['children']) && count($item['children']) > 0) {
                $count += $this->countConcepts($item['children']);
            }
        }

        return $count;
    }
}
