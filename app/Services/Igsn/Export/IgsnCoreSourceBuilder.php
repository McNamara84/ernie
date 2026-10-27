<?php

declare(strict_types=1);

namespace App\Services\Igsn\Export;

use App\Enums\Igsn\IgsnMetadataValueType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Creators\ResourceCreatorNameResolverService;
use App\Services\DataCite\DataCiteDateFormatService;
use App\Services\DescriptionFormattingService;
use App\Services\Igsn\IgsnDescriptionNormalizerService;
use App\Support\IgsnIdentifier;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/** Public source data only. The XSLT owns the Core element/attribute mapping. */
final class IgsnCoreSourceBuilder
{
    public const NAMESPACE = 'urn:ernie:igsn-core-source:1';

    private DOMDocument $document;

    /** @var list<string> */
    private array $omissions = [];

    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly IgsnCoreVocabulary $vocabulary,
        private readonly ResourceCreatorNameResolverService $names,
        private readonly DescriptionFormattingService $descriptions,
        private readonly IgsnDescriptionNormalizerService $igsnDescriptions,
        private readonly IgsnCoreGeometryMapper $geometries,
    ) {}

    public function build(Resource $resource): IgsnCoreSource
    {
        if (! $resource->isIgsn()) {
            throw new InvalidArgumentException('IGSN Core only supports physical objects.');
        }
        $resource->loadMissing([
            'language', 'publisher', 'titles.titleType', 'alternateIdentifiers',
            'creators.creatorable', 'creators.affiliations', 'contributors.contributorable',
            'contributors.contributorTypes', 'contributors.affiliations', 'descriptions.descriptionType',
            'dates.dateType', 'geoLocations', 'relatedIdentifiers.identifierType', 'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType', 'igsnMetadata.parentResource.igsnMetadata',
            'igsnMetadata.parentResource.landingPage', 'igsnClassifications', 'igsnMetadataValues', 'subjects',
        ]);
        $this->document = new DOMDocument('1.0', 'UTF-8');
        $this->omissions = $this->errors = [];
        $root = $this->document->createElementNS(self::NAMESPACE, 's:resource');
        $this->document->appendChild($root);
        $doi = trim((string) $resource->doi);
        if (preg_match('~^10\.\d{4,9}/\S+$~D', $doi) !== 1) {
            $this->errors[] = 'identifier';
        }
        $this->node($root, 'doi', $doi);
        if (! DataCiteDateFormatService::isPublicationYear((string) $resource->publication_year)) {
            $this->errors[] = 'publication_year';
        }
        $this->node($root, 'publicationYear', (string) $resource->publication_year);
        $language = $this->language($resource->language?->code);
        if ($language === 'en') {
            $this->node($root, 'language', 'en');
        } elseif ($language !== null) {
            $this->omissions[] = 'resource_language_unsupported';
        }
        $this->buildNames($resource, $root, $language);
        $this->buildParties($resource, $root);
        $this->buildPublisher($resource, $root);
        $this->buildSample($resource, $root, $language);
        $this->buildDates($resource, $root);
        $this->buildRelations($resource, $root);
        $this->buildFunding($resource, $root);
        $geometry = $this->geometries->map($resource);
        $locations = $this->node($root, 'locations');
        foreach ($geometry['places'] as $place) {
            $this->node($locations, 'place', $place);
        }
        foreach ($geometry['geometries'] as $type => $coordinates) {
            $shape = $this->node($locations, $type);
            foreach ($coordinates as $point) {
                $this->node($shape, 'coordinates', null, $point);
            }
        }
        array_push($this->omissions, ...$geometry['omissions']);
        if ($this->errors !== []) {
            throw new IgsnCoreMetadataException(array_values(array_unique($this->errors)));
        }

        return new IgsnCoreSource($this->document, array_values(array_unique($this->omissions)));
    }

    private function buildNames(Resource $resource, DOMElement $root, ?string $language): void
    {
        $names = $this->node($root, 'names');
        $seen = [];
        foreach ($resource->alternateIdentifiers->sortBy('position') as $identifier) {
            if (! in_array(strtolower($identifier->type), ['local accession number', 'local sample name'], true)) {
                continue;
            }
            $value = trim($identifier->value);
            if ($value !== '' && ! isset($seen[$value])) {
                $this->node($names, 'name', $value, ['type' => 'Other']);
                $seen[$value] = true;
            }
        }
        foreach ($resource->titles->sortBy('id') as $title) {
            $value = trim($title->value);
            $titleLanguage = $this->language($title->language) ?? $language;
            $type = $title->titleType?->slug;
            if ($value === '' || isset($seen[$value])) {
                continue;
            }
            if ($titleLanguage !== 'en') {
                $this->omissions[] = 'title_language_unsupported';

                continue;
            }
            if ($type !== null && $type !== 'MainTitle' && ! $this->vocabulary->allows('titleType', $type)) {
                $this->omissions[] = 'title_type_unsupported';

                continue;
            }
            $this->node($names, 'name', $value, ['type' => $type === 'MainTitle' ? null : $type, 'language' => 'en']);
            $seen[$value] = true;
        }
        if ($seen === []) {
            $this->errors[] = 'name';
        }
    }

    private function buildParties(Resource $resource, DOMElement $root): void
    {
        $creators = $this->node($root, 'creators');
        $contributors = $this->node($root, 'contributors');
        $sampledBy = $resource->igsnMetadataValues->where('type', IgsnMetadataValueType::SampledBy)->pluck('value')->all();
        $parties = [];
        foreach ($resource->creators->sortBy('position') as $creator) {
            $party = $this->party($creators, $creator);
            if ($party !== null) {
                $name = $party->getAttribute('name');
                $party->setAttribute('role', 'creator');
                $parties[$name][] = $party;
            } else {
                $this->errors[] = 'creators';
            }
        }
        if (! $creators->hasChildNodes()) {
            $this->errors[] = 'creators';
        }
        foreach ($sampledBy as $name) {
            if (isset($parties[$name]) && count($parties[$name]) === 1) {
                $parties[$name][0]->setAttribute('role', 'collector');
            } else {
                $this->omissions[] = 'sampled_by_unresolved';
            }
        }
        foreach ($resource->contributors->sortBy('position') as $contributor) {
            $roles = $contributor->contributorTypes->pluck('slug')->unique()->values()->all();
            foreach ($roles === [] ? [null] : $roles as $role) {
                if ($role !== null && ! $this->vocabulary->allows('contributorType', $role)) {
                    $this->omissions[] = 'contributor_role_unsupported';

                    continue;
                }
                $party = $this->party($contributors, $contributor);
                if ($party !== null && $role !== null) {
                    $party->setAttribute('role', $role);
                } elseif ($party === null) {
                    $this->omissions[] = 'contributor_name_missing';
                }
            }
        }
    }

    private function party(DOMElement $parent, ResourceCreator|ResourceContributor $author): ?DOMElement
    {
        $identity = $author->getRelation($author instanceof ResourceCreator ? 'creatorable' : 'contributorable');
        $given = $family = null;
        if ($identity instanceof Person) {
            if ($author instanceof ResourceCreator && $author->hasNameSnapshot()) {
                $given = $this->names->filled($author->given_name_snapshot);
                $family = $this->names->filled($author->family_name_snapshot);
                $name = $this->names->filled($author->name_snapshot) ?? $this->names->format($family, $given);
            } else {
                $given = $this->names->filled($identity->given_name);
                $family = $this->names->filled($identity->family_name);
                $name = $this->names->format($family, $given);
            }
        } elseif ($identity instanceof Institution) {
            $name = $this->names->filled($identity->name);
        } else {
            return null;
        }
        if ($name === null) {
            return null;
        }
        $party = $this->node($parent, 'party', null, [
            'name' => $name, 'type' => $identity instanceof Person ? 'Personal' : 'Organizational',
            'given' => $given, 'family' => $family,
        ]);
        if ($this->names->filled($identity->name_identifier) !== null && $this->names->filled($identity->name_identifier_scheme) !== null) {
            $this->node($party, 'identifier', $identity->name_identifier, ['scheme' => $identity->name_identifier_scheme, 'uri' => $identity->scheme_uri]);
        }
        foreach ($author->affiliations->sortBy('id') as $affiliation) {
            if (trim($affiliation->name) === '') {
                continue;
            }
            $identified = $this->names->filled($affiliation->identifier) !== null && $this->names->filled($affiliation->identifier_scheme) !== null;
            $this->node($party, 'affiliation', $affiliation->name, [
                'identifier' => $identified ? $affiliation->identifier : null,
                'scheme' => $identified ? $affiliation->identifier_scheme : null,
                'uri' => $identified ? $affiliation->scheme_uri : null,
            ]);
        }

        return $party;
    }

    private function buildPublisher(Resource $resource, DOMElement $root): void
    {
        $publisher = $resource->publisher ?? Publisher::getDefault();
        if ($publisher === null || trim($publisher->name) === '' || ! $this->vocabulary->allows('publisherIdentifierScheme', $publisher->identifier_scheme)
            || ! $this->absoluteUri($publisher->identifier) || ! $this->absoluteUri($publisher->scheme_uri)
            || $this->language($publisher->language) !== 'en') {
            $this->errors[] = 'publisher';

            return;
        }
        $this->node($root, 'publisher', $publisher->name, [
            'identifier' => $publisher->identifier, 'scheme' => $publisher->identifier_scheme, 'uri' => $publisher->scheme_uri,
        ]);
    }

    private function buildSample(Resource $resource, DOMElement $root, ?string $language): void
    {
        $metadata = $resource->igsnMetadata;
        $sample = $this->node($root, 'sample');
        $this->node($sample, 'type', $this->names->filled($metadata?->sample_type) ?? (string) $resource->resourceType?->name);
        $material = $this->names->filled($metadata?->material);
        if ($material !== null) {
            $this->material($resource, $sample, $material);
        }
        foreach ($resource->igsnClassifications->sortBy('position')->unique('value') as $classification) {
            if (trim($classification->value) !== '') {
                $this->material($resource, $sample, $classification->value);
            }
        }
        foreach ($resource->descriptions->sortBy('id') as $description) {
            $this->description($sample, $description->value, $description->descriptionType->slug, $this->language($description->language) ?? $language);
        }
        if ($metadata !== null) {
            $this->description($sample, $metadata->collection_method_description, 'Methods', $language);
            $this->description($sample, $metadata->platform_description, 'Other', $language);
            foreach ($this->igsnDescriptions->normalizeCsvPayload($metadata->description_json) as $group) {
                foreach ($group['entries'] as $entry) {
                    $this->description($sample, $entry['value'], 'Other', $language);
                }
            }
            if ($this->names->filled($metadata->current_archive) !== null) {
                $this->node($sample, 'archive', $metadata->current_archive);
            }
            if ($this->names->filled($metadata->original_archive) !== null && $metadata->original_archive !== $metadata->current_archive) {
                $this->omissions[] = 'historical_archive_not_supported';
            }
        }
    }

    private function material(Resource $resource, DOMElement $sample, string $value): void
    {
        // Only enrich a material explicitly identified as such, never arbitrary keywords.
        $matches = $resource->subjects->filter(fn ($subject): bool => $subject->isControlled() && $subject->value === $value && $this->language($subject->language) === 'en');
        $subject = $matches->count() === 1 ? $matches->first() : null;
        if ($subject !== null) {
            $this->node($sample, 'material', $value, [
                'scheme' => $subject->subject_scheme, 'uri' => $subject->scheme_uri,
                'valueUri' => $subject->value_uri, 'code' => $subject->classification_code,
            ]);
        } else {
            $this->node($sample, 'material', $value);
        }
    }

    private function description(DOMElement $parent, ?string $value, ?string $type, ?string $language): void
    {
        if ($value === null || trim($value) === '') {
            return;
        }
        if (! $this->vocabulary->allows('descriptionType', $type) || ! in_array($language, ['en', 'de'], true)) {
            $this->omissions[] = 'description_type_or_language_unsupported';

            return;
        }
        $text = $this->descriptions->formatForStorage($value)['plainText'];
        if ($text !== '') {
            $this->node($parent, 'description', $text, ['type' => $type, 'language' => $language]);
        }
    }

    private function buildDates(Resource $resource, DOMElement $root): void
    {
        $dates = $this->node($root, 'dates');
        foreach ($resource->dates->sortBy('id') as $date) {
            $value = $date->start_date !== null || $date->end_date !== null
                ? ($date->start_date ?? '').'/'.($date->end_date ?? '')
                : trim((string) $date->date_value);
            $type = $date->dateType->slug;
            if (! DataCiteDateFormatService::isDate($value) || ! $this->vocabulary->allows('dateType', $type)) {
                $this->omissions[] = 'date_invalid';

                continue;
            }
            $information = $date->date_information;
            $precision = $this->names->filled($resource->igsnMetadata?->collection_date_precision);
            if ($type === 'Collected' && $precision !== null) {
                $information = implode('; ', array_filter([$information, $precision]));
            }
            $this->node($dates, 'date', $value, ['type' => $type, 'information' => $information]);
        }
    }

    private function buildRelations(Resource $resource, DOMElement $root): void
    {
        $relations = $this->node($root, 'identifiers');
        foreach ($resource->alternateIdentifiers->sortBy('position') as $identifier) {
            if ($this->vocabulary->allows('relatedIdentifierType', $identifier->type) && trim($identifier->value) !== '') {
                $this->node($relations, 'alternate', $identifier->value, ['type' => $identifier->type]);
            } elseif (! in_array(strtolower($identifier->type), ['local accession number', 'local sample name'], true)) {
                $this->omissions[] = 'alternate_identifier_type_unsupported';
            }
        }
        $parent = $resource->igsnMetadata?->parentResource;
        $parentDoi = null;
        $hiddenParent = $parent !== null && (! $parent->landingPage?->isPublished() || $parent->igsnMetadata?->is_private);
        if ($parent !== null && $parent->landingPage?->isPublished() && $parent->igsnMetadata !== null && ! $parent->igsnMetadata->is_private && is_string($parent->doi)) {
            $handle = IgsnIdentifier::handleFromDoi($parent->doi);
            if ($handle !== null) {
                $this->node($relations, 'parent', $handle);
                $parentDoi = $parent->doi;
            } else {
                $this->omissions[] = 'parent_identifier_unresolved';
            }
        }
        $seen = [];
        foreach ($resource->relatedIdentifiers->sortBy('position') as $identifier) {
            $type = $identifier->identifierType->name;
            $relation = $identifier->relationType->name;
            if ($hiddenParent && $identifier->identifier === $parent->doi) {
                continue;
            }
            if ($parentDoi !== null && $identifier->identifier === $parentDoi && $relation === 'IsPartOf') {
                continue;
            }
            if (! $this->vocabulary->allows('relatedIdentifierType', $type) || ! $this->vocabulary->allows('relationType', $relation) || trim($identifier->identifier) === '') {
                $this->omissions[] = 'related_identifier_unsupported';

                continue;
            }
            $key = $type.'|'.$relation.'|'.$identifier->identifier;
            if (! isset($seen[$key])) {
                $this->node($relations, 'related', $identifier->identifier, ['type' => $type, 'relation' => $relation]);
                $seen[$key] = true;
            }
        }
    }

    private function buildFunding(Resource $resource, DOMElement $root): void
    {
        $funding = $this->node($root, 'funding');
        foreach ($resource->fundingReferences->sortBy('id') as $reference) {
            if (trim($reference->funder_name) === '') {
                $this->omissions[] = 'funder_name_missing';

                continue;
            }
            $funder = $this->node($funding, 'funder', null, ['name' => $reference->funder_name]);
            if ($this->names->filled($reference->funder_identifier) !== null) {
                $type = $reference->funderIdentifierType?->name;
                if ($this->vocabulary->allows('funderIdentifierType', $type)) {
                    $this->node($funder, 'identifier', $reference->funder_identifier, ['type' => $type, 'uri' => $reference->scheme_uri]);
                } else {
                    $this->omissions[] = 'funder_identifier_type_unsupported';
                }
            }
            if ($this->names->filled($reference->award_number) !== null) {
                $this->node($funder, 'award', $reference->award_number, ['uri' => $reference->award_uri]);
            }
            if ($this->names->filled($reference->award_title) !== null) {
                $this->node($funder, 'title', $reference->award_title);
            }
        }
    }

    /** @param array<string, string|null> $attributes */
    private function node(DOMElement $parent, string $name, ?string $value = null, array $attributes = []): DOMElement
    {
        $node = $this->document->createElementNS(self::NAMESPACE, 's:'.$name);
        if ($value !== null) {
            $node->appendChild($this->document->createTextNode($value));
        }
        foreach ($attributes as $key => $attribute) {
            if ($attribute !== null && trim($attribute) !== '') {
                $node->setAttribute($key, $attribute);
            }
        }
        $parent->appendChild($node);

        return $node;
    }

    private function language(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : strtolower(explode('-', trim($value))[0]);
    }

    private function absoluteUri(?string $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9+.-]*:\S+$/iD', $value) === 1;
    }
}
