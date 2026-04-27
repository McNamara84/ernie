<?php

declare(strict_types=1);

namespace App\Services\Citations;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Fetches citation metadata from the Crossref REST API.
 *
 * @see https://api.crossref.org/swagger-ui/index.html
 */
class CrossrefClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly CrossrefTypeMapper $typeMapper,
    ) {}

    /**
     * Look up a DOI on Crossref. Returns a CitationLookupResult.
     */
    public function lookup(string $doi): CitationLookupResult
    {
        $doi = $this->normalizeDoi($doi);
        if ($doi === '') {
            return CitationLookupResult::error('crossref', 'Invalid DOI.');
        }

        $baseUrl = (string) config('crossref.base_url', 'https://api.crossref.org/works/');
        $mailto = (string) config('crossref.mailto', '');
        $timeout = (int) config('crossref.timeout', 8);

        try {
            $response = $this->http
                ->timeout($timeout)
                ->retry(1, 200)
                ->acceptJson()
                ->withUserAgent(
                    $mailto !== ''
                        ? "ERNIE/1.0 (mailto:{$mailto})"
                        : 'ERNIE/1.0'
                )
                ->get(rtrim($baseUrl, '/') . '/' . rawurlencode($doi));
        } catch (\Throwable $e) {
            Log::warning('Crossref lookup failed', ['doi' => $doi, 'error' => $e->getMessage()]);

            return CitationLookupResult::error('crossref', 'Crossref request failed.');
        }

        if ($response->status() === 404) {
            return CitationLookupResult::notFound('crossref');
        }

        if (!$response->successful()) {
            return CitationLookupResult::error('crossref', "HTTP {$response->status()}");
        }

        /** @var array<string, mixed>|null $json */
        $json = $response->json();
        if (!is_array($json) || !isset($json['message']) || !is_array($json['message'])) {
            return CitationLookupResult::notFound('crossref');
        }

        return CitationLookupResult::hit('crossref', $this->transform($json['message'], $doi));
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function transform(array $message, string $doi): array
    {
        $titles = [];
        foreach ((array) ($message['title'] ?? []) as $t) {
            if (is_string($t) && trim($t) !== '') {
                $titles[] = ['title' => trim($t), 'titleType' => 'MainTitle'];
                break; // only first title as MainTitle
            }
        }
        foreach ((array) ($message['subtitle'] ?? []) as $s) {
            if (is_string($s) && trim($s) !== '') {
                $titles[] = ['title' => trim($s), 'titleType' => 'Subtitle'];
            }
        }

        $creators = [];
        foreach ((array) ($message['author'] ?? []) as $author) {
            if (!is_array($author)) {
                continue;
            }

            $given = isset($author['given']) && is_string($author['given']) ? trim($author['given']) : null;
            $family = isset($author['family']) && is_string($author['family']) ? trim($author['family']) : null;
            $name = isset($author['name']) && is_string($author['name']) ? trim($author['name']) : null;

            if ($name !== null) {
                $creators[] = [
                    'nameType' => 'Organizational',
                    'name' => $name,
                    'givenName' => null,
                    'familyName' => null,
                    'nameIdentifier' => null,
                    'nameIdentifierScheme' => null,
                    'affiliations' => [],
                ];
                continue;
            }

            if ($family === null && $given === null) {
                continue;
            }

            $orcid = null;
            if (isset($author['ORCID']) && is_string($author['ORCID'])) {
                $orcid = preg_replace('/^https?:\/\/orcid\.org\//i', '', $author['ORCID']);
            }

            $creators[] = [
                'nameType' => 'Personal',
                'name' => trim(($family ?? '') . ($given !== null ? ", {$given}" : '')),
                'givenName' => $given,
                'familyName' => $family,
                'nameIdentifier' => $orcid,
                'nameIdentifierScheme' => $orcid !== null ? 'ORCID' : null,
                'affiliations' => $this->extractAffiliations($author['affiliation'] ?? []),
            ];
        }

        $year = $this->extractYear($message);
        $pages = $this->extractPages($message);

        $publisher = null;
        if (isset($message['publisher']) && is_string($message['publisher'])) {
            $publisher = $message['publisher'];
        }
        // `container-title` (e.g. journal name) is more useful than the publisher imprint.
        if (isset($message['container-title']) && is_array($message['container-title'])) {
            foreach ($message['container-title'] as $ct) {
                if (is_string($ct) && trim($ct) !== '') {
                    $publisher = trim($ct);
                    break;
                }
            }
        }

        $identifiers = [];
        foreach ((array) ($message['ISSN'] ?? []) as $issn) {
            if (is_string($issn)) {
                $identifiers[] = ['identifier' => $issn, 'identifierType' => 'ISSN'];
            }
        }
        foreach ((array) ($message['ISBN'] ?? []) as $isbn) {
            if (is_string($isbn)) {
                $identifiers[] = ['identifier' => $isbn, 'identifierType' => 'ISBN'];
            }
        }

        return [
            'relatedItemType' => $this->typeMapper->map(
                isset($message['type']) && is_string($message['type']) ? $message['type'] : null
            ),
            'titles' => $titles,
            'creators' => $creators,
            'publicationYear' => $year,
            'volume' => isset($message['volume']) && is_string($message['volume']) ? $message['volume'] : null,
            'issue' => isset($message['issue']) && is_string($message['issue']) ? $message['issue'] : null,
            'firstPage' => $pages['first'],
            'lastPage' => $pages['last'],
            'publisher' => $publisher,
            'identifier' => $doi,
            'identifierType' => 'DOI',
            'additionalIdentifiers' => $identifiers,
        ];
    }

    /**
     * @param mixed $raw
     * @return list<array{name: string, affiliationIdentifier: ?string, scheme: ?string}>
     */
    private function extractAffiliations(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $aff) {
            if (!is_array($aff) || !isset($aff['name']) || !is_string($aff['name'])) {
                continue;
            }
            $result[] = [
                'name' => trim($aff['name']),
                'affiliationIdentifier' => null,
                'scheme' => null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $message
     */
    private function extractYear(array $message): ?int
    {
        foreach (['issued', 'published-print', 'published-online', 'created'] as $key) {
            $candidate = $message[$key] ?? null;
            if (is_array($candidate) && isset($candidate['date-parts'][0][0])) {
                $year = (int) $candidate['date-parts'][0][0];
                if ($year > 0) {
                    return $year;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{first: ?string, last: ?string}
     */
    private function extractPages(array $message): array
    {
        if (isset($message['page']) && is_string($message['page'])) {
            $page = trim($message['page']);
            if (str_contains($page, '-')) {
                [$first, $last] = array_pad(explode('-', $page, 2), 2, null);

                return ['first' => trim((string) $first) ?: null, 'last' => trim((string) $last) ?: null];
            }

            return ['first' => $page !== '' ? $page : null, 'last' => null];
        }

        return ['first' => null, 'last' => null];
    }

    private function normalizeDoi(string $doi): string
    {
        $doi = trim($doi);
        $doi = preg_replace('#^https?://(dx\.)?doi\.org/#i', '', $doi) ?? $doi;
        $doi = ltrim($doi, '/');

        return $doi;
    }
}
