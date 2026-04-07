<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Converts DataCite JSON-LD (Linked Data) back to standard DataCite JSON format.
 *
 * This reverses the transformation performed by DataCiteLinkedDataExporter:
 * - Strips @context and @id keys
 * - Unwraps {attrs: {...}, value: "..."} nesting to flat key/value pairs
 * - Unwraps singular wrapper keys (e.g., "creators": {"creator": [...]}) → "creators": [...]
 * - Reconstructs the flat DataCite JSON attributes structure
 *
 * @see DataCiteLinkedDataExporter for the forward transformation
 */
class DataCiteJsonLdToJsonConverterService
{
    /**
     * Convert DataCite JSON-LD to standard DataCite JSON attributes.
     *
     * @param  array<string, mixed>  $jsonLd  The JSON-LD data
     * @return array<string, mixed> DataCite JSON attributes (the content of data.attributes)
     */
    public function convert(array $jsonLd): array
    {
        $attributes = [];

        // Extract DOI from @id
        if (isset($jsonLd['@id']) && is_string($jsonLd['@id'])) {
            $doi = preg_replace('#^https?://doi\.org/#', '', $jsonLd['@id']);
            if ($doi !== null && $doi !== '') {
                $attributes['doi'] = $doi;
                $attributes['identifiers'] = [
                    ['identifier' => $doi, 'identifierType' => 'DOI'],
                ];
            }
        }

        // Identifier (may override from @id)
        if (isset($jsonLd['identifier'])) {
            $attributes['identifiers'] = $this->convertIdentifiers($jsonLd['identifier']);
        }

        // Creators
        if (isset($jsonLd['creators'])) {
            $attributes['creators'] = $this->convertCreators($jsonLd['creators']);
        }

        // Titles
        if (isset($jsonLd['titles'])) {
            $attributes['titles'] = $this->convertTitles($jsonLd['titles']);
        }

        // Publisher
        if (isset($jsonLd['publisher'])) {
            $attributes['publisher'] = $this->convertPublisher($jsonLd['publisher']);
        }

        // Publication Year
        if (isset($jsonLd['publicationYear'])) {
            $attributes['publicationYear'] = $this->unwrapValue($jsonLd['publicationYear']);
        }

        // Resource Type
        if (isset($jsonLd['resourceType'])) {
            $attributes['types'] = $this->convertResourceType($jsonLd['resourceType']);
        }

        // Subjects
        if (isset($jsonLd['subjects'])) {
            $attributes['subjects'] = $this->convertSubjects($jsonLd['subjects']);
        }

        // Contributors
        if (isset($jsonLd['contributors'])) {
            $attributes['contributors'] = $this->convertContributors($jsonLd['contributors']);
        }

        // Dates
        if (isset($jsonLd['dates'])) {
            $attributes['dates'] = $this->convertDates($jsonLd['dates']);
        }

        // Language
        if (isset($jsonLd['language'])) {
            $attributes['language'] = $this->unwrapValue($jsonLd['language']);
        }

        // Alternate Identifiers
        if (isset($jsonLd['alternateIdentifiers'])) {
            $attributes['alternateIdentifiers'] = $this->convertAlternateIdentifiers($jsonLd['alternateIdentifiers']);
        }

        // Related Identifiers
        if (isset($jsonLd['relatedIdentifiers'])) {
            $attributes['relatedIdentifiers'] = $this->convertRelatedIdentifiers($jsonLd['relatedIdentifiers']);
        }

        // Sizes
        if (isset($jsonLd['sizes'])) {
            $attributes['sizes'] = $this->convertSizes($jsonLd['sizes']);
        }

        // Version
        if (isset($jsonLd['version'])) {
            $attributes['version'] = $this->unwrapValue($jsonLd['version']);
        }

        // Rights
        if (isset($jsonLd['rightsList'])) {
            $attributes['rightsList'] = $this->convertRightsList($jsonLd['rightsList']);
        }

        // Descriptions
        if (isset($jsonLd['descriptions'])) {
            $attributes['descriptions'] = $this->convertDescriptions($jsonLd['descriptions']);
        }

        // Geo Locations
        if (isset($jsonLd['geoLocations'])) {
            $attributes['geoLocations'] = $this->convertGeoLocations($jsonLd['geoLocations']);
        }

        // Funding References
        if (isset($jsonLd['fundingReferences'])) {
            $attributes['fundingReferences'] = $this->convertFundingReferences($jsonLd['fundingReferences']);
        }

        return $attributes;
    }

    /**
     * Unwrap a value from {value: "..."} or return plain string.
     *
     * Handles three patterns:
     * 1. JSON-LD wrapped: {attrs: {...}, value: "text"} → "text"
     * 2. Plain string: "text" → "text"
     * 3. Other arrays (non-wrapped): returned as-is for graceful degradation
     */
    private function unwrapValue(mixed $data): mixed
    {
        if (is_array($data) && array_key_exists('value', $data)) {
            return (string) $data['value'];
        }

        if (is_array($data)) {
            return $data;
        }

        return (string) $data;
    }

    /**
     * Ensure data is wrapped as a list (handle single-item unwrapping in JSON-LD).
     *
     * In JSON-LD, single items may be unwrapped from their array. This re-wraps
     * them if needed.
     *
     * @param  mixed  $data  Single item or array of items
     * @return array<int, mixed>
     */
    private function ensureList(mixed $data): array
    {
        if (! is_array($data)) {
            return [$data];
        }

        // If it's an associative array (single item), wrap it
        if ($data !== [] && ! array_is_list($data)) {
            return [$data];
        }

        return $data;
    }

    /**
     * Unwrap the singular wrapper key pattern used in JSON-LD.
     *
     * Example: {"creator": [{...}]} → [{...}]
     *
     * @param  array<array-key, mixed>  $data
     * @return array<int, mixed>
     */
    private function unwrapSingularKey(array $data, string $singularKey): array
    {
        if (isset($data[$singularKey])) {
            return $this->ensureList($data[$singularKey]);
        }

        // Fallback: data might already be the list
        if (array_is_list($data)) {
            return $data;
        }

        return [$data];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function convertIdentifiers(mixed $data): array
    {
        // Single identifier: {attrs: {identifierType: "DOI"}, value: "10.5880/..."}
        if (is_array($data) && isset($data['attrs'])) {
            return [[
                'identifier' => (string) ($data['value'] ?? ''),
                'identifierType' => (string) ($data['attrs']['identifierType'] ?? 'DOI'),
            ]];
        }

        // Multiple identifiers wrapped: {identifier: [{...}, {...}]}
        if (is_array($data) && isset($data['identifier'])) {
            $items = $this->ensureList($data['identifier']);

            return array_map(fn (array $item): array => [
                'identifier' => (string) ($item['value'] ?? ''),
                'identifierType' => (string) ($item['attrs']['identifierType'] ?? 'DOI'),
            ], $items);
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertCreators(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'creator');

        return array_values(array_map(fn (array $creator): array => $this->convertSingleCreator($creator), $items));
    }

    /**
     * @param  array<string, mixed>  $creator
     * @return array<string, mixed>
     */
    private function convertSingleCreator(array $creator): array
    {
        $result = [];

        // Name + nameType
        if (isset($creator['creatorName'])) {
            $result['name'] = $this->unwrapValue($creator['creatorName']);
            if (is_array($creator['creatorName']) && isset($creator['creatorName']['attrs']['nameType'])) {
                $result['nameType'] = $creator['creatorName']['attrs']['nameType'];
            }
        }

        // Given name
        if (isset($creator['givenName'])) {
            $result['givenName'] = $this->unwrapValue($creator['givenName']);
        }

        // Family name
        if (isset($creator['familyName'])) {
            $result['familyName'] = $this->unwrapValue($creator['familyName']);
        }

        // Name identifiers
        if (isset($creator['nameIdentifier'])) {
            $result['nameIdentifiers'] = $this->convertNameIdentifiers($creator['nameIdentifier']);
        }

        // Affiliations
        if (isset($creator['affiliation'])) {
            $result['affiliation'] = $this->convertAffiliations($creator['affiliation']);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function convertNameIdentifiers(mixed $data): array
    {
        $items = $this->ensureList($data);

        return array_values(array_map(function (array $item): array {
            $result = [
                'nameIdentifier' => $this->unwrapValue($item),
            ];
            if (isset($item['attrs'])) {
                if (isset($item['attrs']['nameIdentifierScheme'])) {
                    $result['nameIdentifierScheme'] = $item['attrs']['nameIdentifierScheme'];
                }
                if (isset($item['attrs']['schemeUri'])) {
                    $result['schemeUri'] = $item['attrs']['schemeUri'];
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function convertAffiliations(mixed $data): array
    {
        $items = $this->ensureList($data);

        return array_values(array_map(function (array $item): array {
            $result = [
                'name' => $this->unwrapValue($item),
            ];
            if (isset($item['attrs'])) {
                foreach (['affiliationIdentifier', 'affiliationIdentifierScheme', 'schemeURI'] as $key) {
                    if (isset($item['attrs'][$key])) {
                        $result[$key] = $item['attrs'][$key];
                    }
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertTitles(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'title');

        return array_values(array_map(function (mixed $item): array {
            $result = ['title' => $this->unwrapValue($item)];
            if (is_array($item) && isset($item['attrs'])) {
                if (isset($item['attrs']['titleType'])) {
                    $result['titleType'] = $item['attrs']['titleType'];
                }
                if (isset($item['attrs']['lang'])) {
                    $result['lang'] = $item['attrs']['lang'];
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|null>
     */
    private function convertPublisher(array $data): array
    {
        $result = ['name' => $this->unwrapValue($data)];

        if (isset($data['attrs'])) {
            foreach (['publisherIdentifier', 'publisherIdentifierScheme', 'schemeUri', 'lang'] as $key) {
                if (isset($data['attrs'][$key])) {
                    $result[$key] = $data['attrs'][$key];
                }
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function convertResourceType(array $data): array
    {
        $result = [];

        if (isset($data['attrs']['resourceTypeGeneral'])) {
            $result['resourceTypeGeneral'] = $data['attrs']['resourceTypeGeneral'];
        }

        $result['resourceType'] = $this->unwrapValue($data);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertSubjects(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'subject');

        return array_values(array_map(function (mixed $item): array {
            $result = ['subject' => $this->unwrapValue($item)];

            if (is_array($item) && isset($item['attrs'])) {
                foreach (['subjectScheme', 'schemeUri', 'valueUri', 'classificationCode', 'lang'] as $key) {
                    if (isset($item['attrs'][$key])) {
                        $result[$key] = $item['attrs'][$key];
                    }
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertContributors(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'contributor');

        return array_values(array_map(fn (array $contributor): array => $this->convertSingleContributor($contributor), $items));
    }

    /**
     * @param  array<string, mixed>  $contributor
     * @return array<string, mixed>
     */
    private function convertSingleContributor(array $contributor): array
    {
        $result = [];

        // Contributor type from attrs
        if (isset($contributor['attrs']['contributorType'])) {
            $result['contributorType'] = $contributor['attrs']['contributorType'];
        }

        // Name + nameType
        if (isset($contributor['contributorName'])) {
            $result['name'] = $this->unwrapValue($contributor['contributorName']);
            if (is_array($contributor['contributorName']) && isset($contributor['contributorName']['attrs']['nameType'])) {
                $result['nameType'] = $contributor['contributorName']['attrs']['nameType'];
            }
        }

        // Given name
        if (isset($contributor['givenName'])) {
            $result['givenName'] = $this->unwrapValue($contributor['givenName']);
        }

        // Family name
        if (isset($contributor['familyName'])) {
            $result['familyName'] = $this->unwrapValue($contributor['familyName']);
        }

        // Name identifiers
        if (isset($contributor['nameIdentifier'])) {
            $result['nameIdentifiers'] = $this->convertNameIdentifiers($contributor['nameIdentifier']);
        }

        // Affiliations
        if (isset($contributor['affiliation'])) {
            $result['affiliation'] = $this->convertAffiliations($contributor['affiliation']);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertDates(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'date');

        return array_values(array_map(function (mixed $item): array {
            $result = ['date' => $this->unwrapValue($item)];

            if (is_array($item) && isset($item['attrs'])) {
                if (isset($item['attrs']['dateType'])) {
                    $result['dateType'] = $item['attrs']['dateType'];
                }
                if (isset($item['attrs']['dateInformation'])) {
                    $result['dateInformation'] = $item['attrs']['dateInformation'];
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, string>>
     */
    private function convertAlternateIdentifiers(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'alternateIdentifier');

        return array_values(array_map(function (mixed $item): array {
            return [
                'alternateIdentifier' => $this->unwrapValue($item),
                'alternateIdentifierType' => is_array($item) && isset($item['attrs']['alternateIdentifierType'])
                    ? $item['attrs']['alternateIdentifierType']
                    : '',
            ];
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertRelatedIdentifiers(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'relatedIdentifier');

        return array_values(array_map(function (mixed $item): array {
            $result = ['relatedIdentifier' => $this->unwrapValue($item)];

            if (is_array($item) && isset($item['attrs'])) {
                foreach (['relatedIdentifierType', 'relationType', 'resourceTypeGeneral', 'relationTypeInformation'] as $key) {
                    if (isset($item['attrs'][$key])) {
                        $result[$key] = $item['attrs'][$key];
                    }
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function convertSizes(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'size');

        return array_values(array_map(fn (mixed $item): mixed => $this->unwrapValue($item), $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, string>>
     */
    private function convertRightsList(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'rights');

        return array_values(array_map(function (mixed $item): array {
            $result = ['rights' => $this->unwrapValue($item)];

            if (is_array($item) && isset($item['attrs'])) {
                foreach (['rightsURI', 'rightsIdentifier', 'rightsIdentifierScheme', 'schemeURI', 'lang'] as $key) {
                    if (isset($item['attrs'][$key])) {
                        $result[$key] = $item['attrs'][$key];
                    }
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertDescriptions(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'description');

        return array_values(array_map(function (mixed $item): array {
            $result = ['description' => $this->unwrapValue($item)];

            if (is_array($item) && isset($item['attrs'])) {
                if (isset($item['attrs']['descriptionType'])) {
                    $result['descriptionType'] = $item['attrs']['descriptionType'];
                }
                if (isset($item['attrs']['lang'])) {
                    $result['lang'] = $item['attrs']['lang'];
                }
            }

            return $result;
        }, $items));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertGeoLocations(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'geoLocation');

        return array_values(array_map(fn (array $geo): array => $this->convertSingleGeoLocation($geo), $items));
    }

    /**
     * @param  array<string, mixed>  $geo
     * @return array<string, mixed>
     */
    private function convertSingleGeoLocation(array $geo): array
    {
        $result = [];

        if (isset($geo['geoLocationPlace'])) {
            $result['geoLocationPlace'] = $this->unwrapValue($geo['geoLocationPlace']);
        }

        if (isset($geo['geoLocationPoint'])) {
            $point = $geo['geoLocationPoint'];
            $result['geoLocationPoint'] = [
                'pointLongitude' => $this->unwrapValue($point['pointLongitude'] ?? ''),
                'pointLatitude' => $this->unwrapValue($point['pointLatitude'] ?? ''),
            ];
        }

        if (isset($geo['geoLocationBox'])) {
            $box = $geo['geoLocationBox'];
            $result['geoLocationBox'] = [
                'westBoundLongitude' => $this->unwrapValue($box['westBoundLongitude'] ?? ''),
                'eastBoundLongitude' => $this->unwrapValue($box['eastBoundLongitude'] ?? ''),
                'southBoundLatitude' => $this->unwrapValue($box['southBoundLatitude'] ?? ''),
                'northBoundLatitude' => $this->unwrapValue($box['northBoundLatitude'] ?? ''),
            ];
        }

        if (isset($geo['geoLocationPolygon'])) {
            $polygon = $geo['geoLocationPolygon'];
            $points = [];

            if (isset($polygon['polygonPoint'])) {
                $polygonPoints = $this->ensureList($polygon['polygonPoint']);
                $points = array_map(fn (array $pt): array => [
                    'pointLongitude' => $this->unwrapValue($pt['pointLongitude'] ?? ''),
                    'pointLatitude' => $this->unwrapValue($pt['pointLatitude'] ?? ''),
                ], $polygonPoints);
            }

            $geoPolygon = ['polygonPoints' => $points];

            if (isset($polygon['inPolygonPoint'])) {
                $geoPolygon['inPolygonPoint'] = [
                    'pointLongitude' => $this->unwrapValue($polygon['inPolygonPoint']['pointLongitude'] ?? ''),
                    'pointLatitude' => $this->unwrapValue($polygon['inPolygonPoint']['pointLatitude'] ?? ''),
                ];
            }

            $result['geoLocationPolygon'] = $geoPolygon;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function convertFundingReferences(array $data): array
    {
        $items = $this->unwrapSingularKey($data, 'fundingReference');

        return array_values(array_map(fn (array $item): array => $this->convertSingleFundingReference($item), $items));
    }

    /**
     * @param  array<string, mixed>  $funding
     * @return array<string, mixed>
     */
    private function convertSingleFundingReference(array $funding): array
    {
        $result = [];

        if (isset($funding['funderName'])) {
            $result['funderName'] = $this->unwrapValue($funding['funderName']);
        }

        if (isset($funding['funderIdentifier'])) {
            $result['funderIdentifier'] = $this->unwrapValue($funding['funderIdentifier']);
            if (is_array($funding['funderIdentifier']) && isset($funding['funderIdentifier']['attrs'])) {
                if (isset($funding['funderIdentifier']['attrs']['funderIdentifierType'])) {
                    $result['funderIdentifierType'] = $funding['funderIdentifier']['attrs']['funderIdentifierType'];
                }
                if (isset($funding['funderIdentifier']['attrs']['schemeUri'])) {
                    $result['schemeUri'] = $funding['funderIdentifier']['attrs']['schemeUri'];
                }
            }
        }

        if (isset($funding['awardNumber'])) {
            $result['awardNumber'] = $this->unwrapValue($funding['awardNumber']);
            if (is_array($funding['awardNumber']) && isset($funding['awardNumber']['attrs']['awardUri'])) {
                $result['awardUri'] = $funding['awardNumber']['attrs']['awardUri'];
            }
        }

        if (isset($funding['awardTitle'])) {
            $result['awardTitle'] = $this->unwrapValue($funding['awardTitle']);
        }

        return $result;
    }
}
