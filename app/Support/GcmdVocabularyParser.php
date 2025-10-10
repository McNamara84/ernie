<?php

namespace App\Support;

class GcmdVocabularyParser
{
    /**
     * Extract total hits from RDF content
     */
    public function extractTotalHits(string $rdfContent): int
    {
        $xml = new \SimpleXMLElement($rdfContent);
        $xml->registerXPathNamespace('gcmd', 'https://gcmd.earthdata.nasa.gov/kms#');
        
        $hits = $xml->xpath('//gcmd:gcmd/gcmd:hits');
        
        return $hits ? (int) $hits[0] : 0;
    }

    /**
     * Extract concepts from RDF content
     * 
     * @return array<int, array<string, string|null>>
     */
    public function extractConcepts(string $rdfContent): array
    {
        $xml = new \SimpleXMLElement($rdfContent);
        $xml->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
        $xml->registerXPathNamespace('skos', 'http://www.w3.org/2004/02/skos/core#');
        $xml->registerXPathNamespace('dcterms', 'http://purl.org/dc/terms/');

        $conceptElements = $xml->xpath('//skos:Concept');
        
        if ($conceptElements === false || $conceptElements === null) {
            return [];
        }
        
        $concepts = [];

        foreach ($conceptElements as $concept) {
            $rdfNs = $concept->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $id = (string) ($rdfNs['about'] ?? '');
            
            // Convert UUID to full URL if necessary
            if ($id && !str_starts_with($id, 'http')) {
                $id = 'https://gcmd.earthdata.nasa.gov/kms/concept/' . $id;
            }
            
            $skosNs = $concept->children('http://www.w3.org/2004/02/skos/core#');
            $prefLabel = (string) ($skosNs->prefLabel ?? '');
            $definition = (string) ($skosNs->definition ?? '');
            
            // Get language (default to 'en')
            $language = 'en';
            if ($skosNs->prefLabel) {
                $langAttr = $skosNs->prefLabel->attributes('http://www.w3.org/XML/1998/namespace');
                if ($langAttr && isset($langAttr['lang'])) {
                    $language = (string) $langAttr['lang'];
                }
            }

            // Get broader relationship
            $broaderId = null;
            if ($skosNs->broader) {
                $broaderAttr = $skosNs->broader->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $broaderId = (string) ($broaderAttr['resource'] ?? '');
                
                // Convert UUID to full URL if necessary
                if ($broaderId && !str_starts_with($broaderId, 'http')) {
                    $broaderId = 'https://gcmd.earthdata.nasa.gov/kms/concept/' . $broaderId;
                }
            }

            $concepts[] = [
                'id' => $id,
                'text' => $prefLabel,
                'language' => $language,
                'description' => $definition,
                'broaderId' => $broaderId,
            ];
        }

        return $concepts;
    }

    /**
     * Build hierarchical structure from flat concept array
     * 
     * @param array<int, array<string, string|null>> $concepts
     * @param string $schemeTitle The title of the vocabulary scheme (e.g., "NASA/GCMD Earth Science Keywords")
     * @param string $schemeURI The URI of the vocabulary scheme (e.g., "https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords")
     * @return array<string, mixed>
     */
    public function buildHierarchy(array $concepts, string $schemeTitle, string $schemeURI): array
    {
        $conceptsById = [];
        /** @var array<string, array<int, string>> */
        $childrenByParentId = [];

        // First pass: index all concepts and group children by parent ID
        foreach ($concepts as $concept) {
            $id = $concept['id'];
            
            // Skip concepts without valid IDs
            if ($id === null || $id === '') {
                continue;
            }
            
            $conceptsById[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'language' => $concept['language'],
                'scheme' => $schemeTitle,
                'schemeURI' => $schemeURI,
                'description' => $concept['description'],
                'children' => [],
            ];

            // Group children by parent ID (only if broaderId is not null)
            if ($concept['broaderId'] !== null) {
                $broaderId = $concept['broaderId'];
                if (!isset($childrenByParentId[$broaderId])) {
                    $childrenByParentId[$broaderId] = [];
                }
                $childrenByParentId[$broaderId][] = $id;
            }
        }

        // Find root concept IDs (concepts with no parent or parent doesn't exist)
        $allChildIds = [];
        foreach ($childrenByParentId as $childIds) {
            $allChildIds = array_merge($allChildIds, $childIds);
        }
        $allChildIds = array_unique($allChildIds);
        
        $rootIds = [];
        foreach ($conceptsById as $id => $concept) {
            if (!in_array($id, $allChildIds)) {
                $rootIds[] = $id;
            }
        }

        // Build tree only from root concepts (top-down approach)
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
     * Recursively build a tree node and all its descendants
     *
     * @param string|null $nodeId The ID of the current node
     * @param array<string, array<string, mixed>> $conceptsById Indexed concepts
     * @param array<string, array<int, string>> $childrenByParentId Map of parent IDs to child IDs
     * @return array<string, mixed>|null The built tree node or null if node doesn't exist
     */
    private function buildTreeNode(?string $nodeId, array &$conceptsById, array &$childrenByParentId): ?array
    {
        if ($nodeId === null || !isset($conceptsById[$nodeId])) {
            return null;
        }

        $node = $conceptsById[$nodeId];
        $childIds = $childrenByParentId[$nodeId] ?? [];
        
        // Recursively build children
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
