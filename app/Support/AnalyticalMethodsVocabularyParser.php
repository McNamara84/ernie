<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parser for the ARDC Linked Data API response of the "Analytical Methods
 * for Geochemistry and Cosmochemistry" vocabulary (EarthChem/GEOROC).
 *
 * Transforms ARDC JSON format into a hierarchical tree structure compatible
 * with the existing vocabulary format used in ERNIE.
 */
class AnalyticalMethodsVocabularyParser
{
    private const SCHEME_TITLE = 'Analytical Methods for Geochemistry and Cosmochemistry';

    private const SCHEME_URI = 'https://w3id.org/geochem/1.0/analyticalmethod/method';

    /**
     * Parse ARDC Linked Data API response items into flat concept array.
     *
     * @param  array<int, array<string, mixed>>  $items  Raw items from ARDC API response
     * @return array<int, array{id: string, text: string, notation: string, language: string, broaderId: string|null, definition: string}>
     */
    public function extractConcepts(array $items): array
    {
        $concepts = [];

        foreach ($items as $item) {
            $uri = $item['_about'] ?? '';

            if ($uri === '' || ! is_string($uri)) {
                continue;
            }

            $englishLabel = $this->extractEnglishLabel($item['prefLabel'] ?? []);

            if ($englishLabel === null) {
                continue;
            }

            $broaderId = $this->extractBroaderUri($item['broader'] ?? []);
            $notation = is_string($item['notation'] ?? null) ? $item['notation'] : '';
            $definition = is_string($item['definition'] ?? null) ? $item['definition'] : '';

            $concepts[] = [
                'id' => $uri,
                'text' => $englishLabel,
                'notation' => $notation,
                'language' => 'en',
                'broaderId' => $broaderId,
                'definition' => $definition,
            ];
        }

        return $concepts;
    }

    /**
     * Build hierarchical structure from flat concept array.
     *
     * @param  array<int, array{id: string, text: string, notation: string, language: string, broaderId: string|null, definition: string}>  $concepts
     * @return array{lastUpdated: string, data: array<int, array<string, mixed>>}
     */
    public function buildHierarchy(array $concepts): array
    {
        /** @var array<string, array<string, mixed>> $conceptsById */
        $conceptsById = [];
        /** @var array<string, list<string>> $childrenByParentId */
        $childrenByParentId = [];

        foreach ($concepts as $concept) {
            $id = $concept['id'];

            $conceptsById[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'notation' => $concept['notation'],
                'language' => $concept['language'],
                'scheme' => self::SCHEME_TITLE,
                'schemeURI' => self::SCHEME_URI,
                'description' => $concept['definition'],
                'children' => [],
            ];

            if ($concept['broaderId'] !== null) {
                $childrenByParentId[$concept['broaderId']][] = $id;
            }
        }

        $rootIds = [];
        foreach ($concepts as $concept) {
            $id = $concept['id'];
            if ($concept['broaderId'] === null || ! isset($conceptsById[$concept['broaderId']])) {
                $rootIds[] = $id;
            }
        }

        $rootConcepts = [];
        foreach ($rootIds as $rootId) {
            $rootNode = $this->buildTreeNode($rootId, $conceptsById, $childrenByParentId);
            if ($rootNode !== null) {
                $rootConcepts[] = $rootNode;
            }
        }

        return [
            'lastUpdated' => now()->format('Y-m-d H:i:s'),
            'data' => $rootConcepts,
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
     * Extract the English label from a prefLabel array or object.
     *
     * @param  mixed  $prefLabel
     */
    private function extractEnglishLabel(mixed $prefLabel): ?string
    {
        if (! is_array($prefLabel)) {
            return null;
        }

        // Single label object: {"_value": "...", "_lang": "en"}
        if (isset($prefLabel['_value'], $prefLabel['_lang'])) {
            return $prefLabel['_lang'] === 'en' ? $prefLabel['_value'] : null;
        }

        // Array of label objects
        foreach ($prefLabel as $label) {
            if (is_array($label) && isset($label['_value'], $label['_lang']) && $label['_lang'] === 'en') {
                return $label['_value'];
            }
        }

        return null;
    }

    /**
     * Extract the broader (parent) URI from the broader field.
     *
     * Takes the first broader URI as the canonical parent.
     * Multi-parent concepts are placed under their first listed parent.
     *
     * @param  mixed  $broader
     */
    private function extractBroaderUri(mixed $broader): ?string
    {
        if (is_string($broader) && $broader !== '') {
            return $broader;
        }

        if (! is_array($broader)) {
            return null;
        }

        // Single object with _about key
        if (isset($broader['_about']) && is_string($broader['_about']) && $broader['_about'] !== '') {
            return $broader['_about'];
        }

        // Array of strings or objects — take the first
        foreach ($broader as $item) {
            if (is_string($item) && $item !== '') {
                return $item;
            }

            if (is_array($item) && isset($item['_about']) && is_string($item['_about']) && $item['_about'] !== '') {
                return $item['_about'];
            }
        }

        return null;
    }

    /**
     * Recursively build a tree node and all its descendants.
     *
     * @param  array<string, array<string, mixed>>  $conceptsById
     * @param  array<string, list<string>>  $childrenByParentId
     * @return array<string, mixed>|null
     */
    private function buildTreeNode(string $nodeId, array &$conceptsById, array &$childrenByParentId): ?array
    {
        if (! isset($conceptsById[$nodeId])) {
            return null;
        }

        $node = $conceptsById[$nodeId];
        $childIds = $childrenByParentId[$nodeId] ?? [];

        $node['children'] = [];
        foreach ($childIds as $childId) {
            $childNode = $this->buildTreeNode($childId, $conceptsById, $childrenByParentId);
            if ($childNode !== null) {
                $node['children'][] = $childNode;
            }
        }

        return $node;
    }
}
