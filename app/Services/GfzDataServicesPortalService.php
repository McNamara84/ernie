<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GfzDataServicesPortalService
{
    private const DATACENTER_CACHE_KEY = 'gfz_data_services_portal:datacenters';

    public function __construct(
        private readonly DoiSuggestionService $doiSuggestionService,
    ) {}

    /**
     * @return list<array{id: string, name: string, resource_count: int}>
     */
    public function listDatacenters(): array
    {
        $ttl = max(0, (int) config('datacite.legacy_portal.datacenter_cache_ttl_seconds', 600));

        if ($ttl === 0) {
            return $this->fetchDatacenters();
        }

        /** @var list<array{id: string, name: string, resource_count: int}> $datacenters */
        $datacenters = Cache::remember(
            self::DATACENTER_CACHE_KEY,
            now()->addSeconds($ttl),
            fn (): array => $this->fetchDatacenters(),
        );

        return $datacenters;
    }

    /**
     * @return array{id: string, name: string, resource_count: int}|null
     */
    public function findDatacenter(string $id): ?array
    {
        $id = trim($id);

        foreach ($this->listDatacenters() as $datacenter) {
            if (hash_equals($datacenter['id'], $id)) {
                return $datacenter;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     datacenter: array{id: string, name: string, resource_count: int},
     *     resources: array<string, list<string>>
     * }
     */
    public function resourcesForDatacenter(string $id): array
    {
        $datacenter = $this->findDatacenter($id);

        if ($datacenter === null) {
            throw new RuntimeException('The selected GFZ Data Services datacenter is no longer available.');
        }

        $pageSize = max(1, min(
            1000,
            (int) config('datacite.legacy_portal.page_size', 500),
        ));
        $start = 0;
        $total = null;
        /** @var array<string, list<string>> $resources */
        $resources = [];

        do {
            $response = $this->postQuery([
                'q' => '*:*',
                'rows' => $pageSize,
                'start' => $start,
                'fl' => 'doi,datacentre_facet',
                'sort' => 'doi asc',
                'json.nl' => 'map',
                'fq' => sprintf(
                    'datacentre_facet:"%s" AND -type:text',
                    $this->escapeSolrQuotedValue($this->facetValue($datacenter)),
                ),
            ]);
            $payload = $this->jsonPayload($response);
            $responseData = $payload['response'] ?? null;

            if (! is_array($responseData)) {
                throw new RuntimeException('The GFZ Data Services portal returned an invalid resource response.');
            }

            $pageTotal = $responseData['numFound'] ?? null;
            $documents = $responseData['docs'] ?? null;

            if (! is_numeric($pageTotal) || ! is_array($documents)) {
                throw new RuntimeException('The GFZ Data Services portal resource response is incomplete.');
            }

            $total ??= max(0, (int) $pageTotal);

            foreach ($documents as $document) {
                if (! is_array($document) || ! is_string($document['doi'] ?? null)) {
                    Log::warning('Skipping malformed GFZ Data Services portal resource', [
                        'datacenter_id' => $datacenter['id'],
                    ]);

                    continue;
                }

                $doi = $this->doiSuggestionService->normalizeDoi($document['doi']);

                if ($doi === '' || ! $this->doiSuggestionService->isValidDoiFormat($doi)) {
                    Log::warning('Skipping invalid DOI from GFZ Data Services portal', [
                        'datacenter_id' => $datacenter['id'],
                    ]);

                    continue;
                }

                $names = $this->datacenterNamesFromDocument($document);

                if ($names === []) {
                    $names = [$datacenter['name']];
                }

                $resources[$doi] = array_values(array_unique(array_merge(
                    $resources[$doi] ?? [],
                    $names,
                )));
            }

            $documentCount = count($documents);
            $start += $documentCount;

            if ($documentCount === 0 && $start < $total) {
                throw new RuntimeException('The GFZ Data Services portal pagination ended unexpectedly.');
            }
        } while ($start < $total);

        ksort($resources);

        return [
            'datacenter' => $datacenter,
            'resources' => $resources,
        ];
    }

    public function clearDatacenterCache(): void
    {
        Cache::forget(self::DATACENTER_CACHE_KEY);
    }

    /**
     * @return list<array{id: string, name: string, resource_count: int}>
     */
    private function fetchDatacenters(): array
    {
        $response = $this->postQuery([
            'q' => '*:*',
            'facet' => 'true',
            'facet.field' => 'datacentre_facet',
            'facet.limit' => 196,
            'facet.mincount' => 1,
            'rows' => 0,
            'json.nl' => 'map',
            'fq' => '-type:text',
        ]);
        $payload = $this->jsonPayload($response);
        $facets = $payload['facet_counts']['facet_fields']['datacentre_facet'] ?? null;

        if (! is_array($facets)) {
            throw new RuntimeException('The GFZ Data Services portal returned an invalid datacenter response.');
        }

        $datacenters = [];

        foreach ($facets as $facetValue => $count) {
            if (! is_string($facetValue) || ! is_numeric($count)) {
                continue;
            }

            $parsed = $this->parseFacetValue($facetValue);

            if ($parsed === null) {
                Log::warning('Skipping malformed GFZ Data Services datacenter facet');

                continue;
            }

            $datacenters[$parsed['id']] = [
                'id' => $parsed['id'],
                'name' => $parsed['name'],
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
     */
    private function postQuery(array $parameters): Response
    {
        $url = trim((string) config('datacite.legacy_portal.proxy_url'));

        if ($url === '' || ! str_starts_with($url, 'https://')) {
            throw new RuntimeException('The GFZ Data Services portal proxy URL must use HTTPS.');
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout(max(1, (int) config('datacite.legacy_portal.timeout_seconds', 30)))
                ->retry(
                    max(1, (int) config('datacite.legacy_portal.retry_times', 3)),
                    max(0, (int) config('datacite.legacy_portal.retry_sleep_ms', 500)),
                    throw: false,
                )
                ->post($url, [
                    'query' => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException(
                'The GFZ Data Services portal could not be reached.',
                previous: $exception,
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'The GFZ Data Services portal request failed with HTTP %d.',
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
            throw new RuntimeException('The GFZ Data Services portal returned invalid JSON.');
        }

        return $payload;
    }

    /**
     * @return array{id: string, name: string}|null
     */
    private function parseFacetValue(string $value): ?array
    {
        $parts = explode(' - ', trim($value), 2);

        if (count($parts) !== 2) {
            return null;
        }

        $id = trim($parts[0]);
        $name = trim($parts[1]);

        if ($id === '' || $name === '') {
            return null;
        }

        return [
            'id' => $id,
            'name' => $name,
        ];
    }

    /**
     * @param  array{id: string, name: string, resource_count: int}  $datacenter
     */
    private function facetValue(array $datacenter): string
    {
        return "{$datacenter['id']} - {$datacenter['name']}";
    }

    private function escapeSolrQuotedValue(string $value): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        );
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function datacenterNamesFromDocument(array $document): array
    {
        $rawFacets = $document['datacentre_facet'] ?? [];
        $facetValues = is_array($rawFacets) ? $rawFacets : [$rawFacets];
        $names = [];

        foreach ($facetValues as $facetValue) {
            if (! is_string($facetValue)) {
                continue;
            }

            $parsed = $this->parseFacetValue($facetValue);

            if ($parsed !== null) {
                $names[] = $parsed['name'];
            }
        }

        return array_values(array_unique($names));
    }
}
