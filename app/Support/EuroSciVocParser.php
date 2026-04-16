<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Parser for the European Science Vocabulary (EuroSciVoc) RDF/SKOS-XL file.
 *
 * Extracts concepts from the EuroSciVoc taxonomy and builds a hierarchical
 * tree structure. Supports both SKOS-XL (skosxl:prefLabel → skosxl:literalForm)
 * and plain SKOS (skos:prefLabel) label patterns.
 */
class EuroSciVocParser
{
    private const SKOS_NS = 'http://www.w3.org/2004/02/skos/core#';

    private const SKOSXL_NS = 'http://www.w3.org/2008/05/skos-xl#';

    private const RDF_NS = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';

    private const XML_NS = 'http://www.w3.org/XML/1998/namespace';

    /**
     * Extract concepts from RDF/XML content.
     *
     * Parses SKOS concepts from the EuroSciVoc RDF file, extracting their
     * URIs, English labels, and broader concept relationships.
     *
     * @return array<int, array{id: string, text: string, language: string, broaderId: string|null, isTopConcept: bool}>
     *
     * @throws RuntimeException If the RDF content cannot be parsed
     */
    public function extractConcepts(string $rdfContent, string $conceptSchemeUri): array
    {
        $previousUseErrors = libxml_use_internal_errors(true);

        try {
            $xml = new \SimpleXMLElement($rdfContent, LIBXML_NONET | LIBXML_NOENT);
        } catch (\Exception $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);

            throw new RuntimeException('Failed to parse EuroSciVoc RDF/XML: '.$e->getMessage());
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        $xml->registerXPathNamespace('rdf', self::RDF_NS);
        $xml->registerXPathNamespace('skos', self::SKOS_NS);
        $xml->registerXPathNamespace('skosxl', self::SKOSXL_NS);

        // First, build a map of SKOS-XL label URIs → literal forms (English only)
        $xlLabelMap = $this->buildXlLabelMap($xml);

        $conceptElements = $xml->xpath('//skos:Concept');

        if ($conceptElements === false || $conceptElements === null) {
            return [];
        }

        $concepts = [];

        foreach ($conceptElements as $concept) {
            $rdfAttrs = $concept->attributes(self::RDF_NS);
            $id = (string) ($rdfAttrs['about'] ?? '');

            if ($id === '') {
                continue;
            }

            // Check if this concept belongs to the EuroSciVoc scheme
            if (! $this->conceptBelongsToScheme($concept, $conceptSchemeUri)) {
                continue;
            }

            // Get English label: try SKOS-XL first, then plain SKOS
            $text = $this->getEnglishLabel($concept, $xlLabelMap);

            if ($text === '') {
                continue;
            }

            // Get broader concept URI
            $broaderId = $this->getBroaderConceptUri($concept);

            // Check if this is a top concept
            $isTopConcept = $this->isTopConcept($concept, $conceptSchemeUri);

            $concepts[] = [
                'id' => $id,
                'text' => $text,
                'language' => 'en',
                'broaderId' => $broaderId,
                'isTopConcept' => $isTopConcept,
            ];
        }

        return $concepts;
    }

    /**
     * Build hierarchical structure from flat concept array.
     *
     * @param  array<int, array{id: string, text: string, language: string, broaderId: string|null, isTopConcept: bool}>  $concepts
     * @return array{lastUpdated: string, data: array<int, array<string, mixed>>}
     */
    public function buildHierarchy(array $concepts, string $schemeName, string $schemeUri): array
    {
        /** @var array<string, array<string, mixed>> $conceptsById */
        $conceptsById = [];
        /** @var array<string, list<string>> $childrenByParentId */
        $childrenByParentId = [];
        /** @var list<string> $topConceptIds */
        $topConceptIds = [];

        // First pass: index all concepts
        foreach ($concepts as $concept) {
            $id = $concept['id'];

            $conceptsById[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'language' => $concept['language'],
                'scheme' => $schemeName,
                'schemeURI' => $schemeUri,
                'description' => '',
                'children' => [],
            ];

            if ($concept['isTopConcept']) {
                $topConceptIds[] = $id;
            }

            if ($concept['broaderId'] !== null && $concept['broaderId'] !== '') {
                $childrenByParentId[$concept['broaderId']][] = $id;
            }
        }

        // If no explicit top concepts found, derive them:
        // - concepts that are never referenced as a child of another concept, OR
        // - concepts whose broaderId points to a parent not in the concept set
        if ($topConceptIds === []) {
            $allChildIds = [];
            foreach ($childrenByParentId as $childIds) {
                $allChildIds = array_merge($allChildIds, $childIds);
            }
            $allChildIds = array_unique($allChildIds);

            foreach ($conceptsById as $id => $concept) {
                if (! in_array($id, $allChildIds, true)) {
                    $topConceptIds[] = $id;
                }
            }
        }

        // Rescue orphans: concepts whose broaderId points to a parent outside
        // the concept set would otherwise be silently dropped. Promote them to roots.
        $topConceptIdSet = array_flip($topConceptIds);
        foreach ($childrenByParentId as $parentId => $childIds) {
            if (! isset($conceptsById[$parentId])) {
                foreach ($childIds as $childId) {
                    if (! isset($topConceptIdSet[$childId])) {
                        $topConceptIds[] = $childId;
                        $topConceptIdSet[$childId] = true;
                    }
                }
            }
        }

        // Build tree from top concepts
        $rootConcepts = [];
        foreach ($topConceptIds as $rootId) {
            $rootNode = $this->buildTreeNode($rootId, $conceptsById, $childrenByParentId);
            if ($rootNode !== null) {
                $rootConcepts[] = $rootNode;
            }
        }

        // Sort root concepts alphabetically
        usort($rootConcepts, fn (array $a, array $b): int => strcasecmp((string) $a['text'], (string) $b['text']));

        return [
            'lastUpdated' => now()->toIso8601String(),
            'data' => $rootConcepts,
        ];
    }

    /**
     * Recursively count all concepts in the hierarchy.
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
     * Build a map of SKOS-XL label URIs to their English literal forms.
     *
     * @return array<string, string> Map of label URI → English literal form
     */
    private function buildXlLabelMap(\SimpleXMLElement $xml): array
    {
        $map = [];

        $labelElements = $xml->xpath('//skosxl:Label');

        if ($labelElements === false || $labelElements === null) {
            return $map;
        }

        foreach ($labelElements as $label) {
            $rdfAttrs = $label->attributes(self::RDF_NS);
            $labelUri = (string) ($rdfAttrs['about'] ?? '');

            if ($labelUri === '') {
                continue;
            }

            $skosxlNs = $label->children(self::SKOSXL_NS);

            if (! isset($skosxlNs->literalForm)) {
                continue;
            }

            // Check all literalForm children for English
            foreach ($skosxlNs->literalForm as $literalForm) {
                $langAttr = $literalForm->attributes(self::XML_NS);
                $lang = (string) ($langAttr['lang'] ?? '');

                if ($lang === 'en') {
                    $map[$labelUri] = (string) $literalForm;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Check if a concept belongs to the EuroSciVoc concept scheme.
     */
    private function conceptBelongsToScheme(\SimpleXMLElement $concept, string $schemeUri): bool
    {
        $skosNs = $concept->children(self::SKOS_NS);

        // Check skos:inScheme
        if (isset($skosNs->inScheme)) {
            foreach ($skosNs->inScheme as $inScheme) {
                $rdfAttrs = $inScheme->attributes(self::RDF_NS);
                if ((string) ($rdfAttrs['resource'] ?? '') === $schemeUri) {
                    return true;
                }
            }
        }

        // Check skos:topConceptOf
        if (isset($skosNs->topConceptOf)) {
            foreach ($skosNs->topConceptOf as $topConceptOf) {
                $rdfAttrs = $topConceptOf->attributes(self::RDF_NS);
                if ((string) ($rdfAttrs['resource'] ?? '') === $schemeUri) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the English label for a concept.
     *
     * Tries SKOS-XL label resolution first (via xlLabelMap), then falls
     * back to plain skos:prefLabel.
     *
     * @param  array<string, string>  $xlLabelMap
     */
    private function getEnglishLabel(\SimpleXMLElement $concept, array $xlLabelMap): string
    {
        // Strategy 1: SKOS-XL – resolve via label URI
        $skosxlNs = $concept->children(self::SKOSXL_NS);
        if (isset($skosxlNs->prefLabel)) {
            foreach ($skosxlNs->prefLabel as $prefLabel) {
                $rdfAttrs = $prefLabel->attributes(self::RDF_NS);
                $labelUri = (string) ($rdfAttrs['resource'] ?? '');

                if ($labelUri !== '' && isset($xlLabelMap[$labelUri])) {
                    return $xlLabelMap[$labelUri];
                }
            }
        }

        // Strategy 2: Plain SKOS – direct literal
        $skosNs = $concept->children(self::SKOS_NS);
        if (isset($skosNs->prefLabel)) {
            foreach ($skosNs->prefLabel as $prefLabel) {
                $langAttr = $prefLabel->attributes(self::XML_NS);
                $lang = (string) ($langAttr['lang'] ?? '');

                if ($lang === 'en') {
                    return (string) $prefLabel;
                }
            }

            // If no language-tagged English label, try the first label without xml:lang attribute
            foreach ($skosNs->prefLabel as $candidate) {
                $candidateLang = $candidate->attributes(self::XML_NS);
                if (! isset($candidateLang['lang']) || (string) $candidateLang['lang'] === '') {
                    $text = (string) $candidate;
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }

    /**
     * Get the broader concept URI from a concept element.
     */
    private function getBroaderConceptUri(\SimpleXMLElement $concept): ?string
    {
        $skosNs = $concept->children(self::SKOS_NS);

        if (! isset($skosNs->broader)) {
            return null;
        }

        $rdfAttrs = $skosNs->broader->attributes(self::RDF_NS);
        $broaderUri = (string) ($rdfAttrs['resource'] ?? '');

        return $broaderUri !== '' ? $broaderUri : null;
    }

    /**
     * Check if a concept is a top concept of the given scheme.
     */
    private function isTopConcept(\SimpleXMLElement $concept, string $schemeUri): bool
    {
        $skosNs = $concept->children(self::SKOS_NS);

        if (isset($skosNs->topConceptOf)) {
            foreach ($skosNs->topConceptOf as $topConceptOf) {
                $rdfAttrs = $topConceptOf->attributes(self::RDF_NS);
                if ((string) ($rdfAttrs['resource'] ?? '') === $schemeUri) {
                    return true;
                }
            }
        }

        return false;
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

        // Sort children alphabetically
        if ($node['children'] !== []) {
            usort($node['children'], fn (array $a, array $b): int => strcasecmp((string) $a['text'], (string) $b['text']));
        }

        return $node;
    }
}
