<?php

namespace App\Support;

class GcmdVocabularyParser
{
    /**
     * Build hierarchical structure from flat concept array
     * 
     * @param array<int, array<string, string|null>> $concepts
     * @return array<string, mixed>
     */
    public function buildHierarchy(array $concepts): array
    {
        $schemeURI = 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords';
        $schemeTitle = 'NASA/GCMD Earth Science Keywords';

        $conceptsById = [];
        $childrenMap = [];

        // First pass: index all concepts
        foreach ($concepts as $concept) {
            $id = $concept['id'];
            
            $conceptsById[$id] = [
                'id' => $id,
                'text' => $concept['text'],
                'language' => $concept['language'],
                'scheme' => $schemeTitle,
                'schemeURI' => $schemeURI,
                'description' => $concept['description'],
                'children' => [],
            ];

            // Build parent-child mapping
            if ($concept['broaderId']) {
                $broaderId = $concept['broaderId'];
                if (!isset($childrenMap[$broaderId])) {
                    $childrenMap[$broaderId] = [];
                }
                $childrenMap[$broaderId][] = $id;
            }
        }

        // Second pass: build hierarchy
        foreach ($childrenMap as $parentId => $childIds) {
            if (isset($conceptsById[$parentId])) {
                foreach ($childIds as $childId) {
                    if (isset($conceptsById[$childId])) {
                        // @phpstan-ignore-next-line - Dynamic nested array structure
                        $conceptsById[$parentId]['children'][] = $conceptsById[$childId];
                    }
                }
            }
        }

        // Find root concepts (concepts with no parent)
        $rootConcepts = [];
        foreach ($conceptsById as $id => $concept) {
            $hasParent = false;
            foreach ($childrenMap as $parentId => $childIds) {
                if (in_array($id, $childIds)) {
                    $hasParent = true;
                    break;
                }
            }
            if (!$hasParent) {
                $rootConcepts[] = $concept;
            }
        }

        return [
            'lastUpdated' => now()->format('Y-m-d H:i:s'),
            'data' => $rootConcepts,
        ];
    }
}
