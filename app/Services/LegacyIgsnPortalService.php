<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\IgsnIdentifier;
use App\Support\LegacyIgsnDatacenterCatalog;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class LegacyIgsnPortalService
{
    private const DATACENTER_CACHE_KEY = 'legacy_igsn_portal:datacenters';

    /**
     * @return list<array{id: string, name: string, legacy_name: string, resource_count: int}>
     */
    public function listDatacenters(): array
    {
        $ttl = max(0, (int) config('datacite.legacy_igsn_portal.datacenter_cache_ttl_seconds', 600));

        if ($ttl === 0) {
            return $this->fetchDatacenters();
        }

        /** @var list<array{id: string, name: string, legacy_name: string, resource_count: int}> $datacenters */
        $datacenters = Cache::remember(
            self::DATACENTER_CACHE_KEY,
            now()->addSeconds($ttl),
            fn (): array => $this->fetchDatacenters(),
        );

        return $datacenters;
    }

    /**
     * @return array{id: string, name: string, legacy_name: string, resource_count: int}|null
     */
    public function findDatacenter(string $legacyId): ?array
    {
        $legacyId = trim($legacyId);

        foreach ($this->listDatacenters() as $datacenter) {
            if (hash_equals($datacenter['id'], $legacyId)) {
                return $datacenter;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     datacenter: array{id: string, name: string, legacy_name: string, resource_count: int},
     *     dois: list<string>
     * }
     */
    public function igsnsForDatacenter(string $legacyId): array
    {
        $datacenter = $this->findDatacenter($legacyId);
        if ($datacenter === null) {
            throw new RuntimeException('The selected legacy IGSN datacenter is no longer available.');
        }

        $facetValue = LegacyIgsnDatacenterCatalog::facetValue($datacenter['id']);
        if ($facetValue === null) {
            throw new RuntimeException('The selected legacy IGSN datacenter is not configured.');
        }

        $dois = [];

        foreach ($this->documents([
            'q' => '*:*',
            'fl' => 'igsn,doi,datacentre_facet',
            'sort' => 'igsn asc',
            'fq' => sprintf(
                'datacentre_facet:"%s"',
                $this->escapeSolrQuotedValue($facetValue),
            ),
        ]) as $document) {
            $doi = $this->doiFromDocument($document);
            if ($doi === null) {
                Log::warning('Skipping malformed legacy IGSN portal document', [
                    'legacy_datacenter_id' => $datacenter['id'],
                ]);

                continue;
            }

            $dois[$doi] = true;
        }

        $normalizedDois = array_keys($dois);
        sort($normalizedDois);

        return [
            'datacenter' => $datacenter,
            'dois' => $normalizedDois,
        ];
    }

    /**
     * @return array<string, string> Normalized DOI to canonical datacenter name.
     */
    public function assignmentsForAllIgsns(): array
    {
        $assignments = [];

        foreach ($this->documents([
            'q' => '*:*',
            'fl' => 'igsn,doi,datacentre_facet',
            'sort' => 'igsn asc',
        ]) as $document) {
            $this->addAssignment($assignments, $document);
        }

        ksort($assignments);

        return $assignments;
    }

    /**
     * @param  list<string>  $handles
     * @return array<string, string> Normalized DOI to canonical datacenter name.
     */
    public function assignmentsForHandles(array $handles): array
    {
        $normalizedHandles = [];

        foreach ($handles as $handle) {
            $handle = strtoupper(trim($handle));
            if (IgsnIdentifier::isValidHandle($handle)) {
                $normalizedHandles[$handle] = true;
            }
        }

        if ($normalizedHandles === []) {
            return [];
        }

        $assignments = [];

        foreach (array_chunk(array_keys($normalizedHandles), 100) as $chunk) {
            $quotedHandles = array_map(
                fn (string $handle): string => '"'.$this->escapeSolrQuotedValue($handle).'"',
                $chunk,
            );

            foreach ($this->documents([
                'q' => 'igsn:('.implode(' OR ', $quotedHandles).')',
                'fl' => 'igsn,doi,datacentre_facet',
                'sort' => 'igsn asc',
            ]) as $document) {
                $this->addAssignment($assignments, $document);
            }
        }

        ksort($assignments);

        return $assignments;
    }

    public function clearDatacenterCache(): void
    {
        Cache::forget(self::DATACENTER_CACHE_KEY);
    }

    /**
     * @return list<array{id: string, name: string, legacy_name: string, resource_count: int}>
     */
    private function fetchDatacenters(): array
    {
        $response = $this->postQuery([
            'q' => '*:*',
            'facet' => 'true',
            'facet.field' => 'datacentre_facet',
            'facet.limit' => 100,
            'facet.mincount' => 1,
            'rows' => 0,
            'json.nl' => 'map',
        ]);
        $payload = $this->jsonPayload($response);
        $facets = $payload['facet_counts']['facet_fields']['datacentre_facet'] ?? null;

        if (! is_array($facets)) {
            throw new RuntimeException('The legacy IGSN portal returned an invalid datacenter response.');
        }

        $datacenters = [];

        foreach ($facets as $facetValue => $count) {
            if (! is_string($facetValue) || ! is_numeric($count)) {
                continue;
            }

            $parsed = LegacyIgsnDatacenterCatalog::parseFacetValue($facetValue);
            if ($parsed === null) {
                Log::warning('Skipping unknown legacy IGSN datacenter facet');

                continue;
            }

            $datacenters[$parsed['id']] = [
                ...$parsed,
                'resource_count' => max(0, (int) $count),
            ];
        }

        $datacenters = array_values($datacenters);

        usort(
            $datacenters,
            static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']),
        );

        return $datacenters;
    }

    /**
     * @param  array<string, scalar>  $parameters
     * @return Generator<int, array<string, mixed>>
     */
    private function documents(array $parameters): Generator
    {
        $pageSize = max(1, min(
            1000,
            (int) config('datacite.legacy_igsn_portal.page_size', 500),
        ));
        $start = 0;
        $total = null;

        do {
            $response = $this->postQuery([
                ...$parameters,
                'rows' => $pageSize,
                'start' => $start,
                'json.nl' => 'map',
            ]);
            $payload = $this->jsonPayload($response);
            $responseData = $payload['response'] ?? null;

            if (! is_array($responseData)) {
                throw new RuntimeException('The legacy IGSN portal returned an invalid sample response.');
            }

            $pageTotal = $responseData['numFound'] ?? null;
            $pageDocuments = $responseData['docs'] ?? null;

            if (! is_numeric($pageTotal) || ! is_array($pageDocuments)) {
                throw new RuntimeException('The legacy IGSN portal sample response is incomplete.');
            }

            $total ??= max(0, (int) $pageTotal);

            foreach ($pageDocuments as $document) {
                if (is_array($document)) {
                    yield $document;
                }
            }

            $documentCount = count($pageDocuments);
            $start += $documentCount;

            if ($documentCount === 0 && $start < $total) {
                throw new RuntimeException('The legacy IGSN portal pagination ended unexpectedly.');
            }
        } while ($start < $total);
    }

    /**
     * @param  array<string, string>  $assignments
     * @param  array<string, mixed>  $document
     */
    private function addAssignment(array &$assignments, array $document): void
    {
        $doi = $this->doiFromDocument($document);
        $datacenterName = $this->datacenterNameFromDocument($document);

        if ($doi !== null && $datacenterName !== null) {
            $assignments[$doi] = $datacenterName;
        }
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function doiFromDocument(array $document): ?string
    {
        $handle = $document['igsn'] ?? null;

        if (! is_string($handle) && ! is_numeric($handle)) {
            $legacyDoi = $document['doi'] ?? null;
            if (is_string($legacyDoi) && str_contains($legacyDoi, '/')) {
                $handle = substr($legacyDoi, (int) strrpos($legacyDoi, '/') + 1);
            }
        }

        if (! is_string($handle) && ! is_numeric($handle)) {
            return null;
        }

        $handle = trim((string) $handle);

        return IgsnIdentifier::isValidHandle($handle)
            ? IgsnIdentifier::doiFromHandle($handle)
            : null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function datacenterNameFromDocument(array $document): ?string
    {
        $rawFacets = $document['datacentre_facet'] ?? [];
        $facetValues = is_array($rawFacets) ? $rawFacets : [$rawFacets];

        foreach ($facetValues as $facetValue) {
            if (! is_string($facetValue)) {
                continue;
            }

            $parsed = LegacyIgsnDatacenterCatalog::parseFacetValue($facetValue);
            if ($parsed !== null) {
                return $parsed['name'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, scalar>  $parameters
     */
    private function postQuery(array $parameters): Response
    {
        $url = trim((string) config('datacite.legacy_igsn_portal.proxy_url'));

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('The legacy IGSN portal proxy URL must use HTTPS.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(max(1, (int) config('datacite.legacy_igsn_portal.timeout_seconds', 30)))
                ->retry(
                    max(1, (int) config('datacite.legacy_igsn_portal.retry_times', 3)),
                    max(0, (int) config('datacite.legacy_igsn_portal.retry_sleep_ms', 500)),
                    throw: false,
                )
                ->post($url, [
                    'query' => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'The legacy IGSN portal could not be reached.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'The legacy IGSN portal request failed with HTTP %d.',
                $response->status(),
            ));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new RuntimeException('The legacy IGSN portal returned invalid JSON.');
        }

        return $payload;
    }

    private function escapeSolrQuotedValue(string $value): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        );
    }
}
