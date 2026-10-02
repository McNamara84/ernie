<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class GemetConceptHierarchyParser
{
    /** @param array<string, array<int, array{uri: string, label: string, definition: string}>> $groups
     * @return list<array<string, mixed>>
     */
    public function concepts(string $rdf, array $groups): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement($rdf, LIBXML_NONET);
            $xml->registerXPathNamespace('rdf', 'http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $xml->registerXPathNamespace('skos', 'http://www.w3.org/2004/02/skos/core#');
            $relations = $xml->xpath('//*[@rdf:about]');
        } catch (\Throwable $exception) {
            throw new RuntimeException('The GEMET concept relations are not valid RDF.', 0, $exception);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $concepts = [];
        foreach ($groups as $members) {
            foreach ($members as $member) {
                if (! str_starts_with($member['uri'], 'http://www.eionet.europa.eu/gemet/concept/')) {
                    continue;
                }
                $concepts[$member['uri']] = ['id' => $member['uri'], 'text' => $member['label'], 'description' => $member['definition'], 'broaderIds' => []];
            }
        }
        $seen = [];
        foreach ($relations ?: [] as $relation) {
            $attributes = $relation->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
            $id = $this->absolute((string) ($attributes['about'] ?? ''));
            if (! str_starts_with($id, 'http://www.eionet.europa.eu/gemet/concept/')) {
                continue;
            }
            if (! isset($concepts[$id])) {
                throw new RuntimeException('GEMET relations reference a concept missing from the local labels.');
            }
            $seen[$id] = true;
            foreach ($relation->children('http://www.w3.org/2004/02/skos/core#')->broader as $broader) {
                $parentAttributes = $broader->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $parent = $this->absolute((string) ($parentAttributes['resource'] ?? ''));
                if (! isset($concepts[$parent])) {
                    throw new RuntimeException('GEMET relations reference an unknown broader concept.');
                }
                $concepts[$id]['broaderIds'][] = $parent;
            }
            foreach ($relation->children('http://www.w3.org/2004/02/skos/core#')->narrower as $narrower) {
                $childAttributes = $narrower->attributes('http://www.w3.org/1999/02/22-rdf-syntax-ns#');
                $child = $this->absolute((string) ($childAttributes['resource'] ?? ''));
                if (! isset($concepts[$child])) {
                    throw new RuntimeException('GEMET relations reference an unknown narrower concept.');
                }
                $concepts[$child]['broaderIds'][] = $id;
            }
        }
        if (count($seen) !== count($concepts) || $concepts === []) {
            throw new RuntimeException('The GEMET concept relation export is incomplete.');
        }

        return array_values($concepts);
    }

    private function absolute(string $uri): string
    {
        return str_starts_with($uri, 'concept/') ? 'http://www.eionet.europa.eu/gemet/'.$uri : $uri;
    }
}
