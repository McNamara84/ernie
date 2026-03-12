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

    /**
     * Fetch all GEMET SuperGroups.
     *
     * @return array<int, array{uri: string, label: string, definition: string}>
     *
     * @throws RuntimeException If the API request fails
     */
    public function fetchSuperGroups(string $language = 'en', int $timeout = 30): array
    {
        $url = self::BASE_URL.'getSuperGroupsForScheme';

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
        $url = self::BASE_URL.'getGroupsForScheme';

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
            $url = self::BASE_URL.'getBroaderConcepts';

            $response = Http::timeout($timeout)
                ->accept('application/json')
                ->get($url, [
                    'concept_uri' => $group['uri'],
                    'language' => $language,
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(
                    "Failed to fetch broader concepts for group {$group['uri']}: HTTP {$response->status()}"
                );
            }

            $data = $response->json();

            if (is_array($data) && count($data) > 0) {
                $firstParent = $data[0];
                $parentUri = $firstParent['uri'] ?? ($firstParent['preferredLabel']['uri'] ?? null);
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
        $url = self::BASE_URL.'getGroupMembers';

        $response = Http::timeout($timeout)
            ->accept('application/json')
            ->get($url, [
                'group_uri' => $groupUri,
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
     * Parse API response entities into a normalized format.
     *
     * @param  array<int, mixed>  $data
     * @return array<int, array{uri: string, label: string, definition: string}>
     */
    private function parseEntities(array $data): array
    {
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
