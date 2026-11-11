<?php

declare(strict_types=1);

namespace App\Services\DcatAp;

use App\Enums\AccessLevel;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Creators\ResourceCreatorNameResolverService;
use App\Services\DataCite\DataCiteDateFormatService;
use App\Services\EmbargoService;
use App\Services\LandingPageContentLinkService;
use App\Services\ResourceAccessLevelResolverService;
use App\Support\IdentifierUrl;
use App\Support\LanguageTag;
use DOMElement;

final class DcatApRdfXmlExporter
{
    public function __construct(
        private readonly DcatApResourceProfileService $profile,
        private readonly DcatApReadinessService $readiness,
        private readonly DcatApVocabularyMapper $vocabulary,
        private readonly DcatApDataThemeResolver $themes,
        private readonly DcatApRightsMapper $rights,
        private readonly DcatApGeometryMapper $geometry,
        private readonly LandingPageContentLinkService $content,
        private readonly ResourceAccessLevelResolverService $access,
        private readonly EmbargoService $embargo,
        private readonly ResourceCreatorNameResolverService $names,
    ) {}

    public function export(Resource $resource): string
    {
        $this->readiness->validateConfiguration();
        $resource->loadMissing($this->profile->requiredRelations());
        if (! $this->readiness->available($resource)) {
            throw new \DomainException('Resource is not available as DCAT-AP: '.implode(', ', $this->readiness->errors($resource)));
        }
        $landing = $resource->landingPage;
        assert($landing !== null);
        // Embargo and source checks must reuse this eager-loaded metadata snapshot.
        $landing->setRelation('resource', $resource);
        $rdf = new RdfDocument;
        $datasetUri = IdentifierUrl::resolve($resource->doi, 'DOI');
        $catalogUri = (string) config('dcatap.catalog.uri');
        $catalog = $rdf->node($rdf->root, 'dcat:Catalog', $catalogUri);
        $rdf->literal($catalog, 'dct:title', (string) config('dcatap.catalog.title'));
        $rdf->literal($catalog, 'dct:description', (string) config('dcatap.catalog.description'));
        $rdf->object($catalog, 'foaf:homepage', 'foaf:Document', $catalogUri);
        $publisher = $rdf->object($catalog, 'dct:publisher', 'foaf:Agent');
        $rdf->literal($publisher, 'foaf:name', (string) config('dcatap.catalog.publisher'));
        $record = $rdf->object($catalog, 'dcat:record', 'dcat:CatalogRecord', self::metadataUrl($resource).'#record');
        $rdf->iri($record, 'foaf:primaryTopic', $datasetUri);
        $timestamp = $resource->updated_at;
        if ($timestamp === null || ($landing->published_at !== null && $landing->published_at->greaterThan($timestamp))) {
            $timestamp = $landing->published_at;
        }
        $rdf->literal($record, 'dct:modified', $timestamp?->copy()->utc()->format('Y-m-d\TH:i:s\Z'), datatype: 'xsd:dateTime');
        $rdf->object($record, 'dct:conformsTo', 'dct:Standard', (string) config('dcatap.profile'));
        $dataset = $rdf->object($catalog, 'dcat:dataset', 'dcat:Dataset', $datasetUri);
        $rdf->iri($dataset, 'rdf:type', RdfDocument::NS['dcat'].'Resource');
        $rdf->literal($dataset, 'dct:identifier', IdentifierUrl::doi($resource->doi));
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
        if ($landing->is_tombstone) {
            $rdf->literal($dataset, 'dct:description', 'This resource has been withdrawn.'.($landing->tombstone_statement ? ' '.$this->readiness->plain($landing->tombstone_statement) : ''), 'en');
        }
        $rdf->literal($dataset, 'dcat:version', $resource->version);
        $rdf->object($dataset, 'dcat:landingPage', 'foaf:Document', $landing->is_tombstone ? url($landing->getPublicPath()) : $landing->public_url);
        if (($language = $this->vocabulary->language($resource->language?->code)) !== null) {
            $rdf->object($dataset, 'dct:language', 'dct:LinguisticSystem', $language);
        }
        $access = $this->vocabulary->access($landing->is_tombstone ? AccessLevel::METADATA_ONLY : $this->access->resolve($resource, $landing));
        if ($access !== null) {
            $rdf->object($dataset, 'dct:accessRights', 'dct:RightsStatement', $access);
        }
        $this->parties($rdf, $dataset, $resource);
        $this->subjects($rdf, $dataset, $resource);
        $this->dates($rdf, $dataset, $resource);
        foreach ($resource->alternateIdentifiers->sortBy([['position', 'asc'], ['id', 'asc']]) as $identifier) {
            if (trim($identifier->value) !== '') {
                $node = $rdf->object($dataset, 'adms:identifier', 'adms:Identifier');
                $rdf->literal($node, 'skos:notation', $identifier->value);
            }
        }
        foreach ($resource->geoLocations->sortBy([['position', 'asc'], ['id', 'asc']]) as $location) {
            $geometry = $this->geometry->map($location);
            if ($geometry === null && trim((string) $location->place) === '') {
                continue;
            }
            $node = $rdf->object($dataset, 'dct:spatial', 'dct:Location');
            $rdf->literal($node, 'skos:prefLabel', $location->place);
            if ($geometry !== null) {
                $rdf->literal($node, $geometry['property'], $geometry['wkt'], datatype: 'gsp:wktLiteral');
            }
        }
        $this->relations($rdf, $dataset, $resource);
        $this->distributions($rdf, $dataset, $resource);

        return $rdf->xml();
    }

    public static function metadataUrl(Resource $resource): string
    {
        return url($resource->landingPage?->getPublicPath().'/metadata/dcat-ap.rdf');
    }

    private function parties(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        $hasEmail = false;
        foreach ($resource->creators->sortBy([['position', 'asc'], ['id', 'asc']]) as $creator) {
            $name = $this->partyName($creator);
            if ($name === null) {
                continue;
            }
            $party = $creator->creatorable;
            $node = $rdf->object($dataset, 'dct:creator', 'foaf:Agent', $this->agentUri($party->name_identifier, $party->name_identifier_scheme));
            $rdf->iri($node, 'rdf:type', RdfDocument::NS['foaf'].($party instanceof Person ? 'Person' : 'Organization'));
            $rdf->literal($node, 'foaf:name', $name);
            if ($creator->is_contact) {
                $hasEmail = $this->contact($rdf, $dataset, $name, $creator->email) || $hasEmail;
            }
        }
        foreach ($resource->contributors->sortBy([['position', 'asc'], ['id', 'asc']]) as $contributor) {
            if ($contributor->contributorTypes->contains(fn ($type): bool => in_array($type->slug, ['ContactPerson', 'contact-person'], true))) {
                $name = $this->partyName($contributor);
                if ($name !== null) {
                    $hasEmail = $this->contact($rdf, $dataset, $name, $contributor->email) || $hasEmail;
                }
            }
        }
        if (! $hasEmail) {
            $this->contact($rdf, $dataset, (string) config('iso19115.metadata_contact.name'), config('iso19115.metadata_contact.email'));
        }
        if ($resource->publisher !== null && trim($resource->publisher->name) !== '') {
            $publisher = $resource->publisher;
            $node = $rdf->object($dataset, 'dct:publisher', 'foaf:Agent', $this->agentUri($publisher->identifier, $publisher->identifier_scheme));
            $rdf->literal($node, 'foaf:name', $publisher->name, $publisher->language);
        }
    }

    private function partyName(ResourceCreator|ResourceContributor $author): ?string
    {
        /** @var Person|Institution|null $party */
        $party = $author instanceof ResourceCreator ? $author->creatorable : $author->contributorable;
        if ($party instanceof Person) {
            $name = $this->names->resolve($author, $party)['name'];

            return $name === 'Unknown' ? null : $name;
        }
        if ($party instanceof Institution) {
            return $this->names->filled($author instanceof ResourceCreator ? ($author->name_snapshot ?? $party->name) : $party->name);
        }

        return null;
    }

    private function agentUri(?string $identifier, ?string $scheme): ?string
    {
        $identifier = trim((string) $identifier);
        if (strtoupper((string) $scheme) === 'ORCID') {
            $bare = preg_replace('~^https?://orcid.org/~i', '', $identifier) ?? '';

            return preg_match('/^\d{4}-\d{4}-\d{4}-\d{3}[\dX]$/D', $bare) ? 'https://orcid.org/'.$bare : null;
        }
        if (strtoupper((string) $scheme) === 'ROR') {
            $bare = preg_replace('~^https?://ror.org/~i', '', $identifier) ?? '';

            return preg_match('/^0[a-z0-9]{6}\d{2}$/D', $bare) ? 'https://ror.org/'.$bare : null;
        }

        return null;
    }

    private function contact(RdfDocument $rdf, DOMElement $dataset, string $name, ?string $email): bool
    {
        if (trim($name) === '') {
            return false;
        }
        $contact = $rdf->object($dataset, 'dcat:contactPoint', 'vcard:Kind');
        $rdf->literal($contact, 'vcard:fn', $name);
        $valid = filter_var(trim((string) $email), FILTER_VALIDATE_EMAIL) !== false;
        if ($valid) {
            $rdf->iri($contact, 'vcard:hasEmail', 'mailto:'.trim((string) $email));
        }

        return $valid;
    }

    private function subjects(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        foreach ($this->themes->resolve($resource) as $theme) {
            $node = $rdf->object($dataset, 'dcat:theme', 'skos:Concept', 'http://publications.europa.eu/resource/authority/data-theme/'.$theme);
            $rdf->literal($node, 'skos:prefLabel', ['TECH' => 'Science and technology', 'ENVI' => 'Environment', 'ENER' => 'Energy'][$theme], 'en');
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

    private function dates(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        $issued = false;
        $updated = false;
        foreach ($resource->dates->sortBy('id') as $date) {
            $value = $date->date_value;
            if ($date->dateType->slug === 'Collected') {
                $start = $date->start_date ?? $value;
                $end = $date->end_date;
                if ($value !== null && str_contains($value, '/')) {
                    [$start, $end] = explode('/', $value, 2);
                }
                if ($this->dateType($start) !== null || $this->dateType($end) !== null) {
                    $node = $rdf->object($dataset, 'dct:temporal', 'dct:PeriodOfTime');
                    $this->dateLiteral($rdf, $node, 'dcat:startDate', $start);
                    $this->dateLiteral($rdf, $node, 'dcat:endDate', $end);
                }
            } elseif ($date->dateType->slug === 'Issued' && ! $issued && $this->dateType($value) !== null) {
                $this->dateLiteral($rdf, $dataset, 'dct:issued', $value);
                $issued = true;
            } elseif ($date->dateType->slug === 'Updated' && ! $updated && $this->dateType($value) !== null) {
                $this->dateLiteral($rdf, $dataset, 'dct:modified', $value);
                $updated = true;
            }
        }
        if (! $issued) {
            $this->dateLiteral($rdf, $dataset, 'dct:issued', (string) $resource->publication_year);
        }
    }

    private function dateType(?string $date): ?string
    {
        if ($date === null || str_contains($date, '/') || ! DataCiteDateFormatService::isDate($date)) {
            return null;
        }

        return match (true) {
            str_contains($date, 'T') => 'xsd:dateTime',
            preg_match('/^-?\d{4}$/D', $date) === 1 => 'xsd:gYear',
            preg_match('/^-?\d{4}-\d{2}$/D', $date) === 1 => 'xsd:gYearMonth',
            default => 'xsd:date',
        };
    }

    private function dateLiteral(RdfDocument $rdf, DOMElement $node, string $property, ?string $date): void
    {
        if (($type = $this->dateType($date)) !== null) {
            // W3CDTF permits minute precision; the XSD dateTime lexical form
            // requires seconds. Preserve the original offset and any fraction.
            if ($type === 'xsd:dateTime' && $date !== null) {
                $date = preg_replace('/(T\d{2}:\d{2})(Z|[+-]\d{2}:\d{2})$/D', '${1}:00$2', $date);
            }
            $rdf->literal($node, $property, $date, datatype: $type);
        }
    }

    private function relations(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
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

    private function distributions(RdfDocument $rdf, DOMElement $dataset, Resource $resource): void
    {
        $landing = $resource->landingPage;
        assert($landing !== null);
        $links = $this->embargo->isEmbargoed($resource) ? [] : $this->content->resolve($resource, $landing)['contentLinks'];
        $rights = $this->rights->resolve($resource);
        if ($links === []) {
            $links = [['url' => null, 'mimeType' => null, 'contentSize' => null]];
        }
        foreach ($links as $index => $link) {
            $distribution = $rdf->object($dataset, 'dcat:distribution', 'dcat:Distribution', self::metadataUrl($resource).'#distribution-'.($index + 1));
            $rdf->iri($distribution, 'dcat:accessURL', $link['url'] ?? ($landing->is_tombstone ? url($landing->getPublicPath()) : $landing->public_url));
            $rdf->iri($distribution, 'dcat:downloadURL', $link['url']);
            if ($link['mimeType'] !== null) {
                if (($mime = $this->vocabulary->mediaType($link['mimeType'])) !== null) {
                    $rdf->object($distribution, 'dcat:mediaType', 'dct:MediaType', $mime);
                }
                if (($format = $this->vocabulary->fileType($link['mimeType'])) !== null) {
                    $rdf->object($distribution, 'dct:format', 'dct:MediaTypeOrExtent', $format);
                }
            }
            if ($link['contentSize'] !== null && preg_match('/^\d+$/D', $link['contentSize'])) {
                $rdf->literal($distribution, 'dcat:byteSize', $link['contentSize'], datatype: 'xsd:nonNegativeInteger');
            }
            if ($rights['license'] !== null) {
                $license = $rdf->object($distribution, 'dct:license', 'dct:LicenseDocument', $rights['license']['uri']);
                $rdf->literal($license, 'rdfs:label', $rights['license']['label']);
            } elseif ($rights['statements'] !== []) {
                $statement = $rdf->object($distribution, 'dct:rights', 'dct:RightsStatement');
                $rdf->literal($statement, 'rdfs:label', implode("\n", $rights['statements']));
            }
        }
    }
}
