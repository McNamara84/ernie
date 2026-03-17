<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Service for fetching data from the GEMET REST API.
 *
 * Handles retrieval of SuperGroups, Groups, and Concepts from the
 * European Environment Agency's GEMET thesaurus API.
 */
class GemetApiService
{
    private const BASE_URL = 'https://www.eionet.europa.eu/gemet/';

    private const SUPERGROUP_URI = 'http://www.eionet.europa.eu/gemet/supergroup/';

    private const GROUP_URI = 'http://www.eionet.europa.eu/gemet/group/';

    private const RELATION_BROADER = 'http://www.w3.org/2004/02/skos/core#broader';

    private const RELATION_GROUP_MEMBER = 'http://www.eionet.europa.eu/gemet/2004/06/gemet-schema.rdf#groupMember';

    /**
     * Fetch all GEMET SuperGroups.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchSuperGroups(string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getTopmostConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'thesaurus_uri' => self::SUPERGROUP_URI,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch GEMET SuperGroups: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Unexpected GEMET API response format for SuperGroups');
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch all GEMET Groups.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchGroups(string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getTopmostConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'thesaurus_uri' => self::GROUP_URI,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch GEMET Groups: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException('Unexpected GEMET API response format for Groups');
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch the SuperGroup parent for each Group.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, string> Map of group URI => supergroup URI
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchGroupToSuperGroupMapping(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $mapping = [];

        foreach ($groups as $group) {
            $url = self::BASE_URL.'getRelatedConcepts';

            $response = Http::timeout($timeout)
                ->accept('application/json')
                ->get($url, [
                    'concept_uri' => $group['uri'],
                    'relation_uri' => self::RELATION_BROADER,
                    'language' => $language,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch broader concepts for group {$group['uri']}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (is_array($data) && count($data) > 0) {
                // GEMET API returns a single object when there is exactly one
                // related concept, or a numeric array when there are multiple.
                $firstParent = array_is_list($data) ? $data[0] : $data;
                $parentUri = $firstParent['uri'] ?? null;
                if (is_string($parentUri) && $parentUri !== '') {
                    $mapping[$group['uri']] = $parentUri;
                }
            }
        }

        return $mapping;
    }

    /**
     * Fetch all concepts belonging to a specific Group.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchConceptsForGroup(string $groupUri, string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getRelatedConcepts';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'concept_uri' => $groupUri,
                'relation_uri' => self::RELATION_GROUP_MEMBER,
                'language' => $language,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Failed to fetch concepts for group {$groupUri}: HTTP {$response->status()}"
            );
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException("Unexpected GEMET API response format for group members: {$groupUri}");
        }

        return $this->parseEntities($data);
    }

    /**
     * Fetch all concepts organized by group.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, array<int, array{uri: string, label: string, definition: string}>> Map of group URI => concepts
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchAllConceptsByGroup(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $conceptsByGroup = [];

        foreach ($groups as $group) {
            $conceptsByGroup[$group['uri']] = $this->fetchConceptsForGroup(
                $group['uri'],
                $language,
                $timeout
            );
        }

        return $conceptsByGroup;
    }

    /**
     * Fetch concept counts per group concurrently using Http::pool().
     *
     * Unlike {@see fetchAllConceptsByGroup()}, this method only returns the count
     * of concepts per group (not the full data), using concurrent HTTP requests
     * for better performance when only counts are needed.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, int> Map of group URI => concept count
     *
     * @throws RuntimeException If any API request fails
     */
    public function fetchConceptCountsByGroup(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getRelatedConcepts';
        $groupUris = array_map(fn (array $group): string => $group['uri'], $groups);

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($url, $groupUris, $language, $timeout): array {
            $requests = [];
            foreach ($groupUris as $groupUri) {
                $requests[] = $pool->timeout($timeout)
                    ->accept('application/json')
                    ->get($url, [
                        'concept_uri' => $groupUri,
                        'relation_uri' => self::RELATION_GROUP_MEMBER,
                        'language' => $language,
                    ]);
            }

            return $requests;
        });

        $counts = [];
        foreach ($groupUris as $index => $groupUri) {
            $response = $responses[$index];

            if ($response instanceof \Throwable) {
                throw new RuntimeException(
                    "HTTP request failed for group {$groupUri}: {$response->getMessage()}"
                );
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch concepts for group {$groupUri}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();
            $counts[$groupUri] = is_array($data) ? count($data) : 0;
        }

        return $counts;
    }

    /**
     * Fetch all concepts organized by group using concurrent HTTP requests.
     *
     * Uses Http::pool() for parallel fetching, significantly faster than
     * the sequential {@see fetchAllConceptsByGroup()} method.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @return array<string, array<int, array{uri: string, label: string, definition: string}>> Map of group URI => concepts
     *
     * @throws RuntimeException If any API request fails
     */
    public function fetchAllConceptsByGroupConcurrently(array $groups, string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getRelatedConcepts';
        $groupUris = array_map(fn (array $group): string => $group['uri'], $groups);

        $responses = Http::pool(function (\Illuminate\Http\Client\Pool $pool) use ($url, $groupUris, $language, $timeout): array {
            $requests = [];
            foreach ($groupUris as $groupUri) {
                $requests[] = $pool->timeout($timeout)
                    ->accept('application/json')
                    ->get($url, [
                        'concept_uri' => $groupUri,
                        'relation_uri' => self::RELATION_GROUP_MEMBER,
                        'language' => $language,
                    ]);
            }

            return $requests;
        });

        $conceptsByGroup = [];
        foreach ($groupUris as $index => $groupUri) {
            $response = $responses[$index];

            if ($response instanceof \Throwable) {
                throw new RuntimeException(
                    "HTTP request failed for group {$groupUri}: {$response->getMessage()}"
                );
            }

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch concepts for group {$groupUri}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (! is_array($data)) {
                throw new RuntimeException("Unexpected GEMET API response format for group members: {$groupUri}");
            }

            $conceptsByGroup[$groupUri] = $this->parseEntities($data);
        }

        return $conceptsByGroup;
    }

    /**
     * Parse API response entities into a normalized format.
     *
     * The GEMET API returns a numeric array of objects when there are multiple
     * results, but a single associative object when there is exactly one result.
     * This method normalizes both formats.
     *
     * @param  array<int|string, mixed>  $data
     * @return array<int, array{uri: string, label: string, definition: string}>
     */
    private function parseEntities(array $data): array
    {
        // GEMET API returns a single object (associative array) for exactly one
        // result. Wrap it in a list so the loop below processes it correctly.
        if (! array_is_list($data) && isset($data['uri'])) {
            $data = [$data];
        }

        $entities = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }

            $uri = $item['uri'] ?? '';
            if (! is_string($uri) || $uri === '') {
                continue;
            }

            $label = '';
            if (isset($item['preferredLabel']['string']) && is_string($item['preferredLabel']['string'])) {
                $label = $item['preferredLabel']['string'];
            }

            $definition = '';
            if (isset($item['definition']['string']) && is_string($item['definition']['string'])) {
                $definition = $item['definition']['string'];
            }

            $entities[] = [
                'uri' => $uri,
                'label' => $label,
                'definition' => $definition,
            ];
        }

        return $entities;
    }
}
