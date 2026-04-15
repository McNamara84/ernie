<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Parser for the ARDC Linked Data API response of the International Chronostratigraphic Chart.
 *
 * Transforms the ARDC JSON format into a hierarchical tree structure compatible
 * with the existing GCMD vocabulary format used in ERNIE.
 */
class ChronostratVocabularyParser
{
    private const SCHEME_TITLE = 'International Chronostratigraphic Chart';

    private const SCHEME_URI = 'http://resource.geosciml.org/vocabulary/timescale/gts2020';

    /**
     * Parse ARDC Linked Data API response items into flat concept array.
     *
     * @param  array<int, array<string, mixed>>  $items  Raw items from ARDC API response
     * @return array<int, array{id: string, text: string, language: string, broaderId: string|null}>
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

            // Skip items without an English label
            if ($englishLabel === null) {
                continue;
            }

            // Filter out boundary/GSSP concepts (e.g., "Base of Bajocian")
            if ($this->isBoundaryConcept($englishLabel)) {
                continue;
            }

            // Extract broader (parent) URI
            $broaderId = $this->extractBroaderUri($item['broader'] ?? []);

            $concepts[] = [
                'id' => $uri,
                'text' => $englishLabel,
                'language' => 'en',
                'broaderId' => $broaderId,
            ];
        }

        return $concepts;
    }

    /**
     * Build hierarchical structure from flat concept array.
     *
     * @param  array<int, array{id: string, text: string, language: string, broaderId: string|null}>  $concepts
     * @return array{lastUpdated: string, data: array<int, array<string, mixed>>}
     */
    public function buildHierarchy(array $concepts): array
    {
        /** @var array<string, array<string, mixed>> $conceptsById */
        $conceptsById = [];
        /** @var array<string, list<string>> $childrenByParentId */
        $childrenByParentId = [];

        // First pass: index all concepts and group children by parent ID
        foreach ($concepts as $concept) {
            $id = $concept['id'];

            $conceptsById[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'language' => $concept['language'],
                'scheme' => self::SCHEME_TITLE,
                'schemeURI' => self::SCHEME_URI,
                'description' => '',
                'children' => [],
            ];

            if ($concept['broaderId'] !== null) {
                $childrenByParentId[$concept['broaderId']][] = $id;
            }
        }

        // Find root concept IDs (concepts with no parent or parent not in dataset)
        $rootIds = [];
        foreach ($concepts as $concept) {
            $id = $concept['id'];
            if ($concept['broaderId'] === null || ! isset($conceptsById[$concept['broaderId']])) {
                $rootIds[] = $id;
            }
        }

        // Build tree from root concepts
        $rootConcepts = [];
        foreach ($rootIds as $rootId) {
            $rootNode = $this->buildTreeNode($rootId, $conceptsById, $childrenByParentId);
            if ($rootNode !== null) {
                $rootConcepts[] = $rootNode;
            }
        }

        return [
            'lastUpdated' => now()->toIso8601String(),
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
     * Extract the English label from a prefLabel array.
     *
     * The ARDC API returns prefLabel as either an array of language-tagged values
     * or a single object with _value and _lang.
     *
     * @param  mixed  $prefLabel
     */
    private function extractEnglishLabel(mixed $prefLabel): ?string
    {
        if (! is_array($prefLabel)) {
            return null;
        }

        // Single label object: {"_value": "Aalenian", "_lang": "en"}
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
     * Check if a concept is a boundary/GSSP concept that should be filtered out.
     */
    private function isBoundaryConcept(string $label): bool
    {
        return str_starts_with($label, 'Base of ')
            || str_starts_with($label, 'GSSP ')
            || str_starts_with($label, 'Stratotype Point');
    }

    /**
     * Extract the broader (parent) URI from the broader field.
     *
     * The broader field can be a single string, an array of strings,
     * or an array of objects with an _about key.
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

        // Single object with _about key: {"_about": "http://..."}
        if (isset($broader['_about']) && is_string($broader['_about']) && $broader['_about'] !== '') {
            return $broader['_about'];
        }

        // Array of strings or objects
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
     * @param  string  $nodeId
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
