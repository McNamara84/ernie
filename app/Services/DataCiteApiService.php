<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CacheKey;
use App\Support\Traits\ChecksCacheTagging;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for fetching DOI metadata through the doi.org Content Negotiation API.
 *
 * Works independently of the DOI registrar (DataCite, Crossref, mEDRA, etc.).
 *
 * API documentation: https://citation.crosscite.org/docs.html
 */
class DataCiteApiService
{
    use ChecksCacheTagging;

    private const DEFAULT_TIMEOUT_SECONDS = 10.0;

    private const RESPONSE_BODY_LOG_EXCERPT_LENGTH = 1000;

    /** Sentinel value stored in cache to represent a confirmed 404. */
    private const CACHE_NULL_SENTINEL = '__NULL__';

    /** Sentinel value indicating a transient failure (not cached long-term). */
    private const CACHE_TRANSIENT_FAILURE = '__TRANSIENT__';

    /**
     * Normalize a DOI string by stripping resolver URL prefixes and lowercasing.
     *
     * @param  string  $doi  The raw DOI string (may include resolver URL)
     * @return string|null The cleaned DOI or null if empty
     */
    public function normalizeDoi(string $doi): ?string
    {
        $cleanDoi = trim($doi);

        // Strip resolver URL prefixes case-insensitively (doi.org, dx.doi.org, with or without trailing slash)
        if (preg_match('/^https?:\/\/(?:dx\.)?doi\.org\/?(.*)$/i', $cleanDoi, $matches)) {
            $cleanDoi = $matches[1];
        }

        $cleanDoi = trim($cleanDoi);

        if ($cleanDoi === '') {
            return null;
        }

        // DOIs are case-insensitive per spec — lowercase for consistent cache keys
        return strtolower($cleanDoi);
    }

    /**
     * Fetch metadata for a DOI through Content Negotiation.
     *
     * Results are cached for 24 hours to reduce load on doi.org.
     *
     * @param  string  $doi  The DOI to fetch metadata for
     * @return array<string, mixed>|null The metadata array, or null on failure
     */
    public function getMetadata(string $doi, ?float $timeoutSeconds = null, bool $cacheTransientFailure = true): ?array
    {
        $cleanDoi = $this->normalizeDoi($doi);

        if ($cleanDoi === null) {
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

        $result = $this->fetchMetadataFromApi($cleanDoi, $doi, $timeoutSeconds ?? self::DEFAULT_TIMEOUT_SECONDS);

        if (is_array($result)) {
            $cache->put($cacheKey, $result, CacheKey::DOI_CITATION->ttl());
        } elseif ($result === self::CACHE_NULL_SENTINEL) {
            // Confirmed 404 — cache for full TTL
            $cache->put($cacheKey, self::CACHE_NULL_SENTINEL, CacheKey::DOI_CITATION->ttl());
        } elseif ($cacheTransientFailure) {
            // Transient failure — cache for 5 minutes to avoid hammering the API
            $cache->put($cacheKey, self::CACHE_TRANSIENT_FAILURE, 300);
        }

        return is_array($result) ? $result : null;
    }

    /**
     * Fetch CSL metadata for multiple DOIs with bounded concurrency and retries.
     *
     * Transient-failure cache sentinels are deliberately retried here: this path
     * backs strict single-resource imports, where a previous best-effort timeout
     * must not turn into a guaranteed incomplete result.
     *
     * @param  array<int, string>  $dois
     * @return array<string, array{status: 'resolved', metadata: array<string, mixed>}|array{status: 'not_found'|'failed', reason: string}>
     */
    public function getMetadataBatch(
        array $dois,
        ?int $concurrency = null,
        ?float $timeoutSeconds = null,
        ?int $attempts = null,
        ?int $retryDelayMs = null,
    ): array {
        $concurrency = max(1, $concurrency ?? (int) config('datacite.citation_labels.required_concurrency', 4));
        $timeoutSeconds = max(0.1, $timeoutSeconds ?? (float) config('datacite.citation_labels.required_timeout_seconds', self::DEFAULT_TIMEOUT_SECONDS));
        $attempts = max(1, $attempts ?? (int) config('datacite.citation_labels.required_attempts', 3));
        $retryDelayMs = max(0, $retryDelayMs ?? (int) config('datacite.citation_labels.required_retry_delay_ms', 500));

        /** @var array<string, true> $normalizedDois */
        $normalizedDois = [];

        foreach ($dois as $doi) {
            $normalizedDoi = $this->normalizeDoi($doi);

            if ($normalizedDoi !== null) {
                $normalizedDois[$normalizedDoi] = true;
            }
        }

        if ($normalizedDois === []) {
            return [];
        }

        $cache = $this->getCacheInstance(CacheKey::DOI_CITATION->tags());
        $results = [];
        $pending = [];

        foreach (array_keys($normalizedDois) as $doi) {
            $cached = $cache->get(CacheKey::DOI_CITATION->key($doi));

            if (is_array($cached)) {
                $results[$doi] = [
                    'status' => 'resolved',
                    'metadata' => $cached,
                ];

                continue;
            }

            if ($cached === self::CACHE_NULL_SENTINEL) {
                $results[$doi] = [
                    'status' => 'not_found',
                    'reason' => 'DOI metadata was not found (HTTP 404).',
                ];

                continue;
            }

            // A transient sentinel from a best-effort request is not authoritative.
            $pending[$doi] = 'DOI metadata request has not completed.';
        }

        for ($attempt = 1; $attempt <= $attempts && $pending !== []; $attempt++) {
            foreach (array_chunk(array_keys($pending), $concurrency) as $chunk) {
                $responses = $this->fetchMetadataPool($chunk, $timeoutSeconds);

                foreach ($chunk as $doi) {
                    $response = $responses[$doi] ?? null;

                    if ($response instanceof \Throwable) {
                        $pending[$doi] = 'Connection error: '.mb_substr($response->getMessage(), 0, 500);

                        continue;
                    }

                    if (! $response instanceof Response) {
                        $pending[$doi] = 'The DOI metadata service returned no response.';

                        continue;
                    }

                    if ($response->successful()) {
                        $metadata = $response->json();

                        if (is_array($metadata)) {
                            $results[$doi] = [
                                'status' => 'resolved',
                                'metadata' => $metadata,
                            ];
                            $cache->put(
                                CacheKey::DOI_CITATION->key($doi),
                                $metadata,
                                CacheKey::DOI_CITATION->ttl(),
                            );
                            unset($pending[$doi]);

                            continue;
                        }

                        $pending[$doi] = 'The DOI metadata service returned a non-JSON response.';

                        continue;
                    }

                    if ($response->status() === 404) {
                        $results[$doi] = [
                            'status' => 'not_found',
                            'reason' => 'DOI metadata was not found (HTTP 404).',
                        ];
                        $cache->put(
                            CacheKey::DOI_CITATION->key($doi),
                            self::CACHE_NULL_SENTINEL,
                            CacheKey::DOI_CITATION->ttl(),
                        );
                        unset($pending[$doi]);

                        continue;
                    }

                    if ($response->status() === 429 || $response->serverError()) {
                        $pending[$doi] = "The DOI metadata service returned HTTP {$response->status()}.";

                        continue;
                    }

                    $results[$doi] = [
                        'status' => 'failed',
                        'reason' => "The DOI metadata service returned permanent HTTP {$response->status()}.",
                    ];
                    unset($pending[$doi]);
                }
            }

            if ($pending !== [] && $attempt < $attempts && $retryDelayMs > 0) {
                usleep($retryDelayMs * $attempt * 1000);
            }
        }

        foreach ($pending as $doi => $reason) {
            $results[$doi] = [
                'status' => 'failed',
                'reason' => $reason,
            ];
        }

        $failures = array_filter(
            $results,
            static fn (array $result): bool => $result['status'] === 'failed',
        );

        if ($failures !== []) {
            Log::warning('DOI citation metadata batch remained incomplete after retries.', [
                'failures' => array_map(
                    static fn (array $result): string => $result['reason'],
                    $failures,
                ),
            ]);
        }

        return $results;
    }

    /**
     * @param  list<string>  $dois
     * @return array<string, Response|\Throwable>
     */
    private function fetchMetadataPool(array $dois, float $timeoutSeconds): array
    {
        try {
            /** @var array<string, Response|\Throwable> $responses */
            $responses = Http::pool(function (Pool $pool) use ($dois, $timeoutSeconds): array {
                $requests = [];

                foreach ($dois as $doi) {
                    $requests[] = $pool->as($doi)
                        ->timeout($timeoutSeconds)
                        ->withHeaders([
                            'Accept' => 'application/vnd.citationstyles.csl+json',
                        ])
                        ->get("https://doi.org/{$doi}");
                }

                return $requests;
            });

            return $responses;
        } catch (\Throwable $exception) {
            return array_fill_keys($dois, $exception);
        }
    }

    /**
     * Fetches metadata from doi.org Content Negotiation API.
     *
     * Returns the metadata array on success, CACHE_NULL_SENTINEL for confirmed 404s,
     * or CACHE_TRANSIENT_FAILURE for server errors / exceptions.
     *
     * @return array<string, mixed>|string
     */
    private function fetchMetadataFromApi(string $cleanDoi, string $originalDoi, float $timeoutSeconds): array|string
    {
        try {
            $url = "https://doi.org/{$cleanDoi}";

            $response = Http::timeout($timeoutSeconds)
                ->withHeaders([
                    'Accept' => 'application/vnd.citationstyles.csl+json',
                ])
                ->get($url);

            if ($response->successful()) {
                $metadata = $response->json();

                if (is_array($metadata)) {
                    return $metadata;
                }

                Log::warning("DOI resolution returned non-JSON metadata for {$originalDoi}", [
                    ...$this->responseLogContext($response),
                ]);

                return self::CACHE_TRANSIENT_FAILURE;
            }

            if ($response->status() === 404) {
                Log::info("DOI not found: {$originalDoi}");

                return self::CACHE_NULL_SENTINEL;
            }

            Log::warning("DOI resolution error for {$originalDoi}", [
                ...$this->responseLogContext($response),
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
     * Build a citation string from CSL JSON metadata.
     *
     * CSL JSON is the standard format returned by the doi.org Content Negotiation API.
     *
     * @param  array<string, mixed>  $metadata  The metadata from doi.org
     * @return string The formatted citation
     */
    public function buildCitationFromMetadata(array $metadata): string
    {
        // Extract authors from CSL JSON.
        $authors = is_array($metadata['author'] ?? null) ? $metadata['author'] : [];
        $authorStrings = [];
        foreach ($authors as $author) {
            if (! is_array($author)) {
                continue;
            }

            $family = $this->metadataString($author['family'] ?? null);
            $given = $this->metadataString($author['given'] ?? null);
            $literal = $this->metadataString($author['literal'] ?? null);

            if ($family !== '' && $given !== '') {
                $authorStrings[] = $family.', '.$this->abbreviateGivenName($given);
            } elseif ($literal !== '') {
                $authorStrings[] = $literal;
            } elseif ($family !== '') {
                $authorStrings[] = $family;
            }
        }
        $authorsString = ! empty($authorStrings) ? implode('; ', $authorStrings) : 'Unknown Author';

        // Extract the year from several possible CSL date fields.
        $year = $this->metadataYear($metadata);

        // Extract the title.
        $title = $this->metadataString($metadata['title'] ?? null, 'Untitled');

        // Extract the publisher.
        $publisher = $this->metadataString($metadata['publisher'] ?? null, 'Unknown Publisher');

        // Extract the DOI.
        $doi = $this->metadataString($metadata['DOI'] ?? null);
        $cleanDoi = $doi !== '' ? $this->normalizeDoi($doi) : null;
        $doiUrl = $cleanDoi !== null ? "https://doi.org/{$cleanDoi}" : '';

        $segments = [
            "{$authorsString} ({$year}): {$title}",
            $publisher,
        ];

        if ($doiUrl !== '') {
            $segments[] = $doiUrl;
        }

        return implode('. ', $segments);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function metadataYear(array $metadata): string
    {
        foreach (['issued', 'published', 'created'] as $field) {
            $date = $metadata[$field] ?? null;

            if (! is_array($date)) {
                continue;
            }

            $year = $this->metadataString($date['date-parts'] ?? null);

            if ($year !== '') {
                return $year;
            }
        }

        return 'n.d.';
    }

    private function metadataString(mixed $value, string $fallback = ''): string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : $fallback;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $string = $this->metadataString($item);

                if ($string !== '') {
                    return $string;
                }
            }
        }

        return $fallback;
    }

    /**
     * Fetch metadata from the DataCite REST API (includes affiliations, nameType, etc.).
     *
     * Only works for DataCite-registered DOIs. Returns the attributes object from:
     * GET https://api.datacite.org/dois/{doi}
     *
     * Results are cached for 24 hours.
     *
     * @param  string  $doi  The DOI to fetch metadata for
     * @return array<string, mixed>|null The DataCite attributes or null on failure
     */
    public function getDataCiteMetadata(string $doi): ?array
    {
        $cleanDoi = $this->normalizeDoi($doi);

        if ($cleanDoi === null) {
            return null;
        }

        $cacheKey = CacheKey::DOI_DATACITE_METADATA->key($cleanDoi);
        $cache = $this->getCacheInstance(CacheKey::DOI_DATACITE_METADATA->tags());

        $cached = $cache->get($cacheKey);

        if ($cached === self::CACHE_NULL_SENTINEL || $cached === self::CACHE_TRANSIENT_FAILURE) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $result = $this->fetchDataCiteMetadataFromApi($cleanDoi, $doi);

        if (is_array($result)) {
            $cache->put($cacheKey, $result, CacheKey::DOI_DATACITE_METADATA->ttl());
        } elseif ($result === self::CACHE_NULL_SENTINEL) {
            $cache->put($cacheKey, self::CACHE_NULL_SENTINEL, CacheKey::DOI_DATACITE_METADATA->ttl());
        } else {
            $cache->put($cacheKey, self::CACHE_TRANSIENT_FAILURE, 300);
        }

        return is_array($result) ? $result : null;
    }

    /**
     * Fetch metadata from the DataCite REST API.
     *
     * @return array<string, mixed>|string
     */
    private function fetchDataCiteMetadataFromApi(string $cleanDoi, string $originalDoi): array|string
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Accept' => 'application/vnd.api+json',
                ])
                ->get("https://api.datacite.org/dois/{$cleanDoi}");

            if ($response->successful()) {
                $attributes = $response->json('data.attributes');

                return is_array($attributes) ? $attributes : self::CACHE_NULL_SENTINEL;
            }

            if ($response->status() === 404) {
                Log::info("DataCite metadata not found: {$originalDoi}");

                return self::CACHE_NULL_SENTINEL;
            }

            Log::warning("DataCite REST API error for {$originalDoi}", [
                ...$this->responseLogContext($response),
            ]);

            return self::CACHE_TRANSIENT_FAILURE;
        } catch (\Exception $e) {
            Log::error("Error fetching DataCite metadata for {$originalDoi}", [
                'error' => $e->getMessage(),
            ]);

            return self::CACHE_TRANSIENT_FAILURE;
        }
    }

    /**
     * @return array{status: int, content_type: string|null, body_excerpt: string, body_truncated: bool}
     */
    private function responseLogContext(Response $response): array
    {
        $body = $response->body();

        return [
            'status' => $response->status(),
            'content_type' => $response->header('Content-Type'),
            'body_excerpt' => substr($body, 0, self::RESPONSE_BODY_LOG_EXCERPT_LENGTH),
            'body_truncated' => strlen($body) > self::RESPONSE_BODY_LOG_EXCERPT_LENGTH,
        ];
    }

    /**
     * Abbreviate a given name to initials for citation display.
     *
     * Each space-separated part is abbreviated independently.
     * Hyphenated parts preserve the hyphen (e.g. Jean-Pierre → J.-P.).
     * Already-abbreviated names with dot pass through unchanged.
     * Single letters without dot get a dot appended (e.g. "A" → "A.").
     */
    private function abbreviateGivenName(string $givenName): string
    {
        $givenName = trim($givenName);

        if ($givenName === '') {
            return '';
        }

        $parts = preg_split('/\\s+/', $givenName) ?: [$givenName];

        $abbreviated = array_map(function (string $part): string {
            return implode('-', array_map(function (string $sub): string {
                // Already abbreviated (e.g. "A." or "A")
                if (mb_strlen($sub) <= 2 && (mb_strlen($sub) === 1 || str_ends_with($sub, '.'))) {
                    return str_ends_with($sub, '.') ? $sub : $sub.'.';
                }

                return mb_strtoupper(mb_substr($sub, 0, 1)).'.';
            }, explode('-', $part)));
        }, $parts);

        return implode(' ', $abbreviated);
    }
}
