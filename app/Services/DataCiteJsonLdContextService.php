<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\JsonLdConversionException;

/**
 * The versioned ERNIE DataCite 4.7 profile, independent of remote context loading.
 */
final class DataCiteJsonLdContextService
{
    public const PATH = '/metadata/contexts/datacite-4.7-v1.jsonld';

    public const FILE = 'resources/data/contexts/datacite-4.7-v1.jsonld';

    public const LEGACY_CONTEXTS = [
        'https://schema.stage.datacite.org/linked-data/context/fullcontext.jsonld',
        'https://schema.datacite.org/meta/kernel-4.7/doc/jsonldcontext.jsonld',
    ];

    private const COLLECTIONS = [
        'creators' => 'creator', 'titles' => 'title', 'subjects' => 'subject',
        'contributors' => 'contributor', 'dates' => 'date',
        'alternateIdentifiers' => 'alternateIdentifier', 'relatedIdentifiers' => 'relatedIdentifier',
        'relatedItems' => 'relatedItem', 'sizes' => 'size', 'formats' => 'format',
        'rightsList' => 'rights', 'descriptions' => 'description',
        'geoLocations' => 'geoLocation', 'fundingReferences' => 'fundingReference',
    ];

    /** Attributes are specific to a node: a known term in the wrong place must not be discarded. */
    private const ATTRIBUTES = [
        'identifier' => ['identifierType'],
        'creatorName' => ['nameType'], 'contributorName' => ['nameType'],
        'contributor' => ['contributorType'],
        'nameIdentifier' => ['nameIdentifierScheme', 'schemeUri', 'schemeURI'],
        'affiliation' => ['affiliationIdentifier', 'affiliationIdentifierScheme', 'schemeUri', 'schemeURI'],
        'title' => ['titleType', 'lang'],
        'publisher' => ['publisherIdentifier', 'publisherIdentifierScheme', 'schemeUri', 'schemeURI', 'lang'],
        'resourceType' => ['resourceTypeGeneral'],
        'subject' => ['subjectScheme', 'schemeUri', 'schemeURI', 'valueUri', 'valueURI', 'classificationCode', 'lang'],
        'date' => ['dateType', 'dateInformation'],
        'alternateIdentifier' => ['alternateIdentifierType'],
        'relatedIdentifier' => ['relatedIdentifierType', 'relationType', 'resourceTypeGeneral', 'relatedMetadataScheme', 'schemeUri', 'schemeURI', 'schemeType', 'relationTypeInformation'],
        'relatedItem' => ['relatedItemType', 'relationType', 'relationTypeInformation'],
        'relatedItemIdentifier' => ['relatedItemIdentifierType', 'relatedMetadataScheme', 'schemeUri', 'schemeURI', 'schemeType'],
        'number' => ['numberType'],
        'rights' => ['rightsIdentifier', 'rightsIdentifierScheme', 'rightsUri', 'rightsURI', 'schemeUri', 'schemeURI', 'lang'],
        'description' => ['descriptionType', 'lang'],
        'funderIdentifier' => ['funderIdentifierType', 'schemeUri', 'schemeURI'],
        'awardNumber' => ['awardUri', 'awardURI'],
    ];

    private const CHILDREN = [
        'creator' => ['creatorName', 'givenName', 'familyName', 'nameIdentifier', 'affiliation'],
        'contributor' => ['contributorName', 'givenName', 'familyName', 'nameIdentifier', 'affiliation'],
        'relatedItem' => ['relatedItemType', 'relationType', 'relationTypeInformation', 'titles', 'creators', 'contributors', 'relatedItemIdentifier', 'publicationYear', 'volume', 'issue', 'number', 'numberType', 'firstPage', 'lastPage', 'publisher', 'edition'],
        'geoLocation' => ['geoLocationPlace', 'geoLocationPoint', 'geoLocationBox', 'geoLocationPolygon'],
        'geoLocationPoint' => ['pointLongitude', 'pointLatitude'],
        'geoLocationBox' => ['westBoundLongitude', 'eastBoundLongitude', 'southBoundLatitude', 'northBoundLatitude'],
        'geoLocationPolygon' => ['polygonPoint', 'inPolygonPoint'],
        'polygonPoint' => ['pointLongitude', 'pointLatitude'],
        'inPolygonPoint' => ['pointLongitude', 'pointLatitude'],
        'fundingReference' => ['funderName', 'funderIdentifier', 'awardNumber', 'awardTitle'],
    ];

    public function url(): string
    {
        $override = trim((string) config('datacite.linked_data.context_url'));

        return $override !== '' ? $override : $this->localUrl();
    }

    public function localUrl(): string
    {
        return rtrim((string) config('app.url'), '/').self::PATH;
    }

    /** Resolve the root identifier to a valid bare DOI, preserving suffix casing. */
    public function doiFromId(mixed $id): string
    {
        if (! is_string($id)) {
            throw new JsonLdConversionException('Invalid DataCite JSON-LD @id: expected a string.');
        }

        $doi = preg_replace('~^https?://(?:dx\.)?doi\.org/~i', '', trim($id));
        if ($doi === null || ! app(DoiSuggestionService::class)->isValidDoiFormat($id)) {
            throw new JsonLdConversionException('Invalid DataCite JSON-LD @id: expected a valid DOI or DOI resolver URL.');
        }

        return $doi;
    }

    /**
     * Validate the supported compact profile before an upload can create a draft.
     * Context URLs identify a contract; they are never fetched during import.
     *
     * @param  array<string, mixed>  $document
     */
    public function assertSupportedDocument(array $document): void
    {
        if (! is_string($document['@context'] ?? null)
            || ! in_array($document['@context'], [$this->localUrl(), $this->url(), ...self::LEGACY_CONTEXTS], true)) {
            throw new JsonLdConversionException('Unsupported JSON-LD context. Upload an ERNIE DataCite JSON-LD export or DataCite JSON/XML.');
        }

        $rootKeys = ['@context', '@id', '@type', 'identifier', 'publisher', 'publicationYear', 'resourceType', 'language', 'version', ...array_keys(self::COLLECTIONS)];
        foreach (array_keys($document) as $key) {
            if (! in_array($key, $rootKeys, true)) {
                throw new JsonLdConversionException('Unsupported DataCite JSON-LD field: '.$key.'.');
            }
        }

        if (isset($document['@type']) && ! in_array($document['@type'], ['Resource', 'https://w3id.org/tib/datacite/class/Resource'], true)) {
            throw new JsonLdConversionException('Unsupported DataCite JSON-LD resource type.');
        }
        if (array_key_exists('@id', $document)) {
            $this->doiFromId($document['@id']);
        }

        foreach ($document as $key => $value) {
            if (! str_starts_with($key, '@')) {
                $this->assertNode($value, $key);
            }
        }
    }

    private function assertNode(mixed $node, string $term): void
    {
        if (isset(self::COLLECTIONS[$term])) {
            if (! is_array($node)) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected an object or list.');
            }
            $singular = self::COLLECTIONS[$term];
            if (array_key_exists($singular, $node)) {
                $this->assertKeys($node, [$singular]);
                $node = $node[$singular];
            }
            $this->assertNode($node, $singular);

            return;
        }
        if (is_array($node) && array_is_list($node)) {
            if (! in_array($term, [...array_values(self::COLLECTIONS), 'nameIdentifier', 'affiliation', 'polygonPoint'], true)) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected a single value or object.');
            }
            foreach ($node as $entry) {
                $this->assertNode($entry, $term);
            }

            return;
        }

        $children = self::CHILDREN[$term] ?? null;
        if ($children === null) {
            // Historical ERNIE profiles also used plain literals in some positions.
            if (! is_array($node)) {
                return;
            }
            $this->assertKeys($node, ['value', 'attrs']);
            if (is_array($node['value'] ?? null)) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected a scalar value.');
            }
        } else {
            if (! is_array($node)) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected an object.');
            }
            if (in_array($term, ['creator', 'contributor'], true) && ! array_key_exists($term.'Name', $node)) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': missing '.$term.'Name.');
            }
            $this->assertKeys($node, [...$children, 'attrs', ...($term === 'relatedItem' ? ['value'] : [])]);
            foreach ($children as $child) {
                if (array_key_exists($child, $node)) {
                    $this->assertNode($node[$child], $child);
                }
            }
            if ($term === 'relatedItem' && array_key_exists('value', $node)) {
                if (! is_array($node['value']) || array_is_list($node['value'])) {
                    throw new JsonLdConversionException('Invalid DataCite JSON-LD relatedItem: expected an object.');
                }
                $this->assertNode($node['value'], 'relatedItem');
            }
        }
        if (array_key_exists('attrs', $node)) {
            if (! is_array($node['attrs'])) {
                throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected an attrs object.');
            }
            $this->assertKeys($node['attrs'], self::ATTRIBUTES[$term] ?? []);
            foreach ($node['attrs'] as $value) {
                if (is_array($value)) {
                    throw new JsonLdConversionException('Invalid DataCite JSON-LD '.$term.': expected scalar attributes.');
                }
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $node
     * @param  list<string>  $allowed
     */
    private function assertKeys(array $node, array $allowed): void
    {
        foreach ($node as $key => $value) {
            if (! in_array($key, $allowed, true)) {
                throw new JsonLdConversionException('Unsupported DataCite JSON-LD field: '.$key.'.');
            }
        }
    }
}
