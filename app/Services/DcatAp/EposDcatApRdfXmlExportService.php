<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Enums\DcatApFormat;
use App\Models\Resource;
use App\Support\IdentifierUrl;
use DOMElement;

final class EposDcatApRdfXmlExportService extends DcatRdfXmlExportService
{
    protected const DcatApFormat FORMAT = DcatApFormat::Epos;

    protected function texts(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        foreach ($resource->titles->sortBy('id') as $title) {
            if ($title->isMainTitle() || in_array($title->titleType?->slug, ['TranslatedTitle', 'translated-title'], true)) {
                $rdf->literal($dataset, 'dct:title', $this->readiness->plain($title->value));
            }
        }
        foreach ($resource->descriptions->sortBy('id') as $description) {
            $text = $this->readiness->plain($description->value);
            if ($description->isAbstract()) {
                $rdf->literal($dataset, 'dct:description', $text);
            } elseif ($text !== '' && $description->descriptionType->slug === 'Methods') {
                $provenance = $rdf->object($dataset, 'dct:provenance', 'dct:ProvenanceStatement');
                $rdf->literal($provenance, 'rdfs:label', $text, $description->language);
            }
        }
    }

    protected function subjects(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        // The pinned EU theme list supplies labels but no concept definitions.
        foreach ($this->themes->resolve($resource) as $theme) {
            $rdf->literal($dataset, 'dcat:keyword', DcatApDataThemeResolver::LABELS[$theme]);
        }
        $mapper = app(EposDcatApConceptLookupService::class);
        foreach ($resource->subjects->sortBy('id') as $subject) {
            $concept = $mapper->concept($subject);
            if ($concept === null) {
                $rdf->literal($dataset, 'dcat:keyword', $subject->value);

                continue;
            }
            $node = $rdf->object($dataset, 'dcat:theme', 'skos:Concept', $concept->valueUri());
            $rdf->literal($node, 'skos:prefLabel', $concept->label);
            $rdf->literal($node, 'skos:definition', $concept->definition);
            $scheme = $rdf->object($node, 'skos:inScheme', 'skos:ConceptScheme', $concept->schemeUri);
            $rdf->literal($scheme, 'dct:title', $concept->scheme);
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
            if ($relation === 'IsDocumentedBy') {
                $rdf->object($dataset, 'foaf:page', 'foaf:Document', $target);
            } else {
                $property = in_array($relation, ['IsCitedBy', 'IsReferencedBy', 'IsSupplementTo'], true) ? 'dct:isReferencedBy' : 'dct:relation';
                $rdf->object($dataset, $property, 'rdfs:Resource', $target);
            }
        }
        foreach ($resource->instruments->sortBy([['position', 'asc'], ['id', 'asc']]) as $instrument) {
            $target = IdentifierUrl::resolve($instrument->instrument_pid, $instrument->instrument_pid_type);
            if ($target !== null) {
                $node = $rdf->object($dataset, 'dct:relation', 'rdfs:Resource', $target);
                $rdf->literal($node, 'rdfs:label', $instrument->instrument_name);
            }
        }
    }
}
