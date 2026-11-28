<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Models\Resource;
use App\Support\IdentifierUrl;
use App\Support\LanguageTag;
use DOMElement;

final class DcatApRdfXmlExporter extends DcatRdfXmlExportService
{
    protected function texts(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        foreach ($resource->titles->sortBy('id') as $title) {
            if ($title->isMainTitle()) {
                $rdf->literal($dataset, 'dct:title', $this->readiness->plain($title->value), $title->language);
            }
        }
        $abstracts = [];
        foreach ($resource->descriptions->sortBy('id') as $description) {
            $text = $this->readiness->plain($description->value);
            if ($text === '') {
                continue;
            }
            if ($description->isAbstract()) {
                $abstracts[LanguageTag::validOrNull($description->language) ?? ''][] = $text;
            } elseif ($description->descriptionType->slug === 'Methods') {
                $provenance = $rdf->object($dataset, 'dct:provenance', 'dct:ProvenanceStatement');
                $rdf->literal($provenance, 'rdfs:label', $text, $description->language);
            }
        }
        ksort($abstracts);
        foreach ($abstracts as $language => $texts) {
            $rdf->literal($dataset, 'dct:description', implode("\n\n", array_unique($texts)), $language);
        }
    }

    protected function subjects(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        foreach ($this->themes->resolve($resource) as $theme) {
            $node = $rdf->object($dataset, 'dcat:theme', 'skos:Concept', 'http://publications.europa.eu/resource/authority/data-theme/'.$theme);
            $rdf->literal($node, 'skos:prefLabel', DcatApDataThemeResolver::LABELS[$theme], 'en');
            $scheme = $rdf->object($node, 'skos:inScheme', 'skos:ConceptScheme', 'http://publications.europa.eu/resource/authority/data-theme');
            $rdf->literal($scheme, 'dct:title', 'Data theme', 'en');
        }
        foreach ($resource->subjects->sortBy('id') as $subject) {
            if (trim($subject->value) === '') {
                continue;
            }
            if (! $subject->isControlled()) {
                $rdf->literal($dataset, 'dcat:keyword', $subject->value, $subject->language);

                continue;
            }
            $node = $rdf->object($dataset, 'dcat:theme', 'skos:Concept', IdentifierUrl::http($subject->value_uri));
            $rdf->literal($node, 'skos:prefLabel', $subject->value, $subject->language);
            if ($subject->subject_scheme || $subject->scheme_uri) {
                $scheme = $rdf->object($node, 'skos:inScheme', 'skos:ConceptScheme', IdentifierUrl::http($subject->scheme_uri));
                $rdf->literal($scheme, 'dct:title', $subject->subject_scheme);
            }
        }
    }

    protected function relations(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        foreach ($resource->relatedIdentifiers->sortBy([['position', 'asc'], ['id', 'asc']]) as $related) {
            $target = IdentifierUrl::resolve($related->identifier, $related->identifierType->slug);
            if ($target === null) {
                continue;
            }
            $relation = $related->relationType->slug;
            $property = match ($relation) {
                'IsCitedBy', 'IsReferencedBy', 'IsSupplementTo' => 'dct:isReferencedBy',
                'IsDocumentedBy' => 'foaf:page',
                'IsDerivedFrom' => 'dct:source',
                'HasVersion', 'IsPreviousVersionOf' => 'dcat:hasVersion',
                default => null,
            };
            if ($property === 'foaf:page') {
                $rdf->object($dataset, $property, 'foaf:Document', $target);
            } elseif (in_array($property, ['dct:source', 'dcat:hasVersion'], true)) {
                $rdf->object($dataset, $property, 'dcat:Dataset', $target);
            } elseif ($property !== null) {
                $rdf->iri($dataset, $property, $target);
            } else {
                $rdf->iri($dataset, 'dct:relation', $target);
                $node = $rdf->object($dataset, 'dcat:qualifiedRelation', 'dcat:Relationship');
                $rdf->iri($node, 'dct:relation', $target);
                $rdf->object($node, 'dcat:hadRole', 'dcat:Role', 'https://datacite-metadata-schema.readthedocs.io/en/4.7/properties/relatedidentifier/#'.strtolower($relation));
            }
        }
    }
}
