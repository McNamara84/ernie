<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service für den Abruf von DOI-Metadaten über die doi.org Content Negotiation API.
 *
 * Funktioniert registrarunabhängig mit allen DOI-Registraren (DataCite, Crossref, mEDRA, etc.)
 *
 * API-Dokumentation: https://citation.crosscite.org/docs.html
 */
class DataCiteApiService
{
    use ChecksCacheTagging;

    /** Sentinel value stored in cache to represent a confirmed 404. */
    private const CACHE_NULL_SENTINEL = '__NULL__';

    /** Sentinel value indicating a transient failure (not cached long-term). */
    private const CACHE_TRANSIENT_FAILURE = '__TRANSIENT__';
    /**
     * Ruft Metadaten für eine DOI über Content Negotiation ab.
     *
     * Results are cached for 24 hours to reduce load on doi.org.
     *
     * @param  string  $doi  Die DOI, für die Metadaten abgerufen werden sollen
     * @return array<string, mixed>|null Die Metadaten als Array oder null bei Fehler
     */
    public function getMetadata(string $doi): ?array
    {
        $cleanDoi = trim(str_replace(
            ['https://doi.org/', 'http://doi.org/', 'https://dx.doi.org/', 'http://dx.doi.org/'],
            '',
            trim($doi),
        ));

        if ($cleanDoi === '') {
            return null;
        }

        $cacheKey = CacheKey::DOI_CITATION->key($cleanDoi);
        $cache = $this->getCacheInstance(CacheKey::DOI_CITATION->tags());

        $cached = $cache->get($cacheKey);

        if ($cached === self::CACHE_NULL_SENTINEL || $cached === self::CACHE_TRANSIENT_FAILURE) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchMetadataFromApi($cleanDoi, $doi);

        if (is_array($result)) {
            $cache->put($cacheKey, $result, CacheKey::DOI_CITATION->ttl());
        } elseif ($result === self::CACHE_NULL_SENTINEL) {
            // Confirmed 404 — cache for full TTL
            $cache->put($cacheKey, self::CACHE_NULL_SENTINEL, CacheKey::DOI_CITATION->ttl());
        } else {
            // Transient failure — cache for 5 minutes to avoid hammering the API
            $cache->put($cacheKey, self::CACHE_TRANSIENT_FAILURE, 300);
        }

        return is_array($result) ? $result : null;
    }

    /**
     * @param  array<string>  $tags
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
     * Fetches metadata from doi.org Content Negotiation API.
     *
     * Returns the metadata array on success, CACHE_NULL_SENTINEL for confirmed 404s,
     * or CACHE_TRANSIENT_FAILURE for server errors / exceptions.
     *
     * @return array<string, mixed>|string
     */
    private function fetchMetadataFromApi(string $cleanDoi, string $originalDoi): array|string
    {
        try {
            $url = "https://doi.org/{$cleanDoi}";

            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/vnd.citationstyles.csl+json',
                ])
                ->get($url);

            if ($response->successful()) {
                return $response->json();
            }

            if ($response->status() === 404) {
                Log::info("DOI not found: {$originalDoi}");

                return self::CACHE_NULL_SENTINEL;
            }

            Log::warning("DOI resolution error for {$originalDoi}", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return self::CACHE_TRANSIENT_FAILURE;
        } catch (\Exception $e) {
            Log::error("Error fetching DOI metadata for {$originalDoi}", [
                'error' => $e->getMessage(),
            ]);

            return self::CACHE_TRANSIENT_FAILURE;
        }
    }

    /**
     * Erstellt einen Zitationsstring aus CSL JSON Metadaten.
     *
     * CSL JSON ist das Standardformat der doi.org Content Negotiation API.
     *
     * @param  array<string, mixed>  $metadata  Die Metadaten von doi.org
     * @return string Die formatierte Zitation
     */
    public function buildCitationFromMetadata(array $metadata): string
    {
        // Autoren aus CSL JSON Format extrahieren
        $authors = $metadata['author'] ?? [];
        $authorStrings = [];
        foreach ($authors as $author) {
            if (isset($author['family']) && isset($author['given'])) {
                $authorStrings[] = $author['family'].', '.$author['given'];
            } elseif (isset($author['literal'])) {
                $authorStrings[] = $author['literal'];
            } elseif (isset($author['family'])) {
                $authorStrings[] = $author['family'];
            }
        }
        $authorsString = ! empty($authorStrings) ? implode('; ', $authorStrings) : 'Unknown Author';

        // Jahr extrahieren - verschiedene mögliche Felder prüfen
        $year = $metadata['issued']['date-parts'][0][0] ??
                $metadata['published']['date-parts'][0][0] ??
                $metadata['created']['date-parts'][0][0] ??
                'n.d.';

        // Titel extrahieren
        $title = $metadata['title'] ?? 'Untitled';

        // Verlag extrahieren
        $publisher = $metadata['publisher'] ?? 'Unknown Publisher';

        // DOI extrahieren
        $doi = $metadata['DOI'] ?? '';
        $doiUrl = $doi ? "https://doi.org/{$doi}" : '';

        // Zitation aufbauen: [Autoren] ([Jahr]): [Titel]. [Verlag]. [DOI URL]
        return trim("{$authorsString} ({$year}): {$title}. {$publisher}. {$doiUrl}");
    }
}
