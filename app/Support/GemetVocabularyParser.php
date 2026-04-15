<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parser for the GEMET REST API responses.
 *
 * Transforms GEMET SuperGroups, Groups, and Concepts into a hierarchical
 * tree structure compatible with the existing vocabulary format used in ERNIE.
 *
 * Hierarchy: SuperGroups → Groups → Concepts (3 levels)
 */
class GemetVocabularyParser
{
    public const SCHEME_TITLE = 'GEMET - GEneral Multilingual Environmental Thesaurus';

    private const SCHEME_URI = 'http://www.eionet.europa.eu/gemet/concept/';

    /**
     * Build hierarchical structure from SuperGroups, Groups, and Concepts.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $superGroups
     * @param  array<int, array{uri: string, label: string, definition: string}>  $groups
     * @param  array<string, string>  $groupToSuperGroupMap  Map of group URI => supergroup URI
     * @param  array<string, array<int, array{uri: string, label: string, definition: string}>>  $conceptsByGroup  Map of group URI => concepts
     * @return array{lastUpdated: string, data: array<int, array<string, mixed>>}
     */
    public function buildHierarchy(
        array $superGroups,
        array $groups,
        array $groupToSuperGroupMap,
        array $conceptsByGroup
    ): array {
        // Index groups by their parent SuperGroup URI
        /** @var array<string, list<array{uri: string, label: string, definition: string}>> $groupsBySuperGroup */
        $groupsBySuperGroup = [];
        foreach ($groups as $group) {
            $parentUri = $groupToSuperGroupMap[$group['uri']] ?? null;
            if ($parentUri !== null) {
                $groupsBySuperGroup[$parentUri][] = $group;
            }
        }

        // Build tree: SuperGroups → Groups → Concepts
        $rootNodes = [];

        foreach ($superGroups as $superGroup) {
            $childGroups = $groupsBySuperGroup[$superGroup['uri']] ?? [];

            $groupNodes = [];
            foreach ($childGroups as $group) {
                $concepts = $conceptsByGroup[$group['uri']] ?? [];
                $conceptNodes = $this->buildConceptNodes($concepts);

                // Sort concepts alphabetically
                usort($conceptNodes, fn (array $a, array $b): int => strcasecmp($a['text'], $b['text']));

                $groupNodes[] = $this->buildNode(
                    $group['uri'],
                    $group['label'],
                    $group['definition'],
                    $conceptNodes
                );
            }

            // Sort groups alphabetically
            usort($groupNodes, fn (array $a, array $b): int => strcasecmp($a['text'], $b['text']));

            $rootNodes[] = $this->buildNode(
                $superGroup['uri'],
                $superGroup['label'],
                $superGroup['definition'],
                $groupNodes
            );
        }

        // Sort super groups alphabetically
        usort($rootNodes, fn (array $a, array $b): int => strcasecmp($a['text'], $b['text']));

        return [
            'lastUpdated' => now()->toIso8601String(),
            'data' => $rootNodes,
        ];
    }

    /**
     * Count total concepts in a hierarchical structure.
     *
     * @param  array<int, array<string, mixed>>  $data
     */
    public function countConcepts(array $data): int
    {
        $count = count($data);

        foreach ($data as $item) {
            if (isset($item['children']) && is_array($item['children']) && count($item['children']) > 0) {
                $count += $this->countConcepts($item['children']);
            }
        }

        return $count;
    }

    /**
     * Build vocabulary nodes from concept entities.
     *
     * @param  array<int, array{uri: string, label: string, definition: string}>  $concepts
     * @return array<int, array<string, mixed>>
     */
    private function buildConceptNodes(array $concepts): array
    {
        $nodes = [];

        foreach ($concepts as $concept) {
            $nodes[] = $this->buildNode(
                $concept['uri'],
                $concept['label'],
                $concept['definition'],
                []
            );
        }

        return $nodes;
    }

    /**
     * Build a single vocabulary node in the standard format.
     *
     * @param  array<int, array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    private function buildNode(string $uri, string $text, string $description, array $children): array
    {
        return [
            'id' => $uri,
            'text' => $text,
            'language' => 'en',
            'scheme' => self::SCHEME_TITLE,
            'schemeURI' => self::SCHEME_URI,
            'description' => $description,
            'children' => $children,
        ];
    }
}
