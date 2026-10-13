<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Resource;

/**
 * Transforms DataCite JSON export into DataCite Linked Data JSON-LD format.
 *
 * Uses ERNIE's versioned DataCite 4.7 context for the attrs/value profile.
 *
 * @see DataCiteJsonLdContextService
 */
class DataCiteLinkedDataExporter
{
    /**
     * Export a Resource as DataCite Linked Data JSON-LD.
     *
     * @return array<string, mixed>
     */
    public function export(Resource $resource): array
    {
        $jsonExporter = new DataCiteJsonExporter;
        $dataCiteJson = $jsonExporter->export($resource, serializeDescriptionsForDataCite: false);
        $attributes = $dataCiteJson['data']['attributes'];

        return $this->exportAttributes($attributes);
    }

    /**
     * Encode the same canonical DataCite attributes used by resource exports.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function exportAttributes(array $attributes): array
    {
        $jsonLd = [
            '@context' => app(DataCiteJsonLdContextService::class)->url(),
            '@type' => 'Resource',
        ];

        $doi = trim((string) ($attributes['doi'] ?? ''));

        // Add DOI-derived fields only if DOI is not blank
        if ($doi !== '') {
            $jsonLd['@id'] = 'https://doi.org/'.$doi;
            $jsonLd['identifier'] = $this->transformSingleIdentifier([
                'identifier' => $doi,
                'identifierType' => 'DOI',
            ]);
        }

        // Creators
        if (! empty($attributes['creators'])) {
            $jsonLd['creators'] = $this->transformCreators($attributes['creators']);
        }

        // Titles
        if (! empty($attributes['titles'])) {
            $jsonLd['titles'] = $this->transformTitles($attributes['titles']);
        }

        // Publisher
        if (! empty($attributes['publisher'])) {
            $jsonLd['publisher'] = $this->transformPublisher($attributes['publisher']);
        }

        // Publication Year
        if (! empty($attributes['publicationYear'])) {
            $jsonLd['publicationYear'] = ['value' => $attributes['publicationYear']];
        }

        // Resource Type
        if (! empty($attributes['types'])) {
            $jsonLd['resourceType'] = $this->transformResourceType($attributes['types']);
        }

        // Subjects
        if (! empty($attributes['subjects'])) {
            $jsonLd['subjects'] = $this->transformSubjects($attributes['subjects']);
        }

        // Contributors
        if (! empty($attributes['contributors'])) {
            $jsonLd['contributors'] = $this->transformContributors($attributes['contributors']);
        }

        // Dates
        if (! empty($attributes['dates'])) {
            $jsonLd['dates'] = $this->transformDates($attributes['dates']);
        }

        // Language
        if (! empty($attributes['language'])) {
            $jsonLd['language'] = ['value' => $attributes['language']];
        }

        // Alternate Identifiers
        if (! empty($attributes['alternateIdentifiers'])) {
            $jsonLd['alternateIdentifiers'] = $this->transformAlternateIdentifiers($attributes['alternateIdentifiers']);
        }

        // Related Identifiers
        if (! empty($attributes['relatedIdentifiers'])) {
            $jsonLd['relatedIdentifiers'] = $this->transformRelatedIdentifiers($attributes['relatedIdentifiers']);
        }

        // Related Items
        if (! empty($attributes['relatedItems'])) {
            $jsonLd['relatedItems'] = $this->transformRelatedItems($attributes['relatedItems']);
        }

        // Sizes
        if (! empty($attributes['sizes'])) {
            $jsonLd['sizes'] = $this->transformSizes($attributes['sizes']);
        }

        // Formats
        if (! empty($attributes['formats'])) {
            $jsonLd['formats'] = $this->transformFormats($attributes['formats']);
        }

        // Version
        if (isset($attributes['version']) && $attributes['version'] !== '') {
            $jsonLd['version'] = ['value' => $attributes['version']];
        }

        // Rights
        if (! empty($attributes['rightsList'])) {
            $jsonLd['rightsList'] = $this->transformRightsList($attributes['rightsList']);
        }

        // Descriptions
        if (! empty($attributes['descriptions'])) {
            $jsonLd['descriptions'] = $this->transformDescriptions($attributes['descriptions']);
        }

        // Geo Locations
        if (! empty($attributes['geoLocations'])) {
            $jsonLd['geoLocations'] = $this->transformGeoLocations($attributes['geoLocations']);
        }

        // Funding References
        if (! empty($attributes['fundingReferences'])) {
            $jsonLd['fundingReferences'] = $this->transformFundingReferences($attributes['fundingReferences']);
        }

        return $jsonLd;
    }

    /**
     * Keep valid literal values such as "0"; only missing attributes are omitted.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function filterAttributes(array $attributes): array
    {
        return array_filter($attributes, fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, string>  $identifier
     * @return array<string, mixed>
     */
    private function transformSingleIdentifier(array $identifier): array
    {
        return [
            'attrs' => ['identifierType' => $identifier['identifierType'] ?? 'DOI'],
            'value' => $identifier['identifier'],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $creators
     * @return array<string, mixed>
     */
    private function transformCreators(array $creators): array
    {
        $transformed = array_map(
            fn (array $creator): array => $this->transformSingleCreator($creator),
            $creators
        );

        return ['creator' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<string, mixed>  $creator
     * @return array<string, mixed>
     */
    private function transformSingleCreator(array $creator): array
    {
        $result = [];

        // Creator name with nameType attribute
        $nameAttrs = [];
        if (isset($creator['nameType'])) {
            $nameAttrs['nameType'] = $creator['nameType'];
        }
        $result['creatorName'] = [
            'attrs' => $nameAttrs,
            'value' => $creator['name'],
        ];

        // Given name
        if (isset($creator['givenName'])) {
            $result['givenName'] = ['value' => $creator['givenName']];
        }

        // Family name
        if (isset($creator['familyName'])) {
            $result['familyName'] = ['value' => $creator['familyName']];
        }

        // Name identifiers
        if (! empty($creator['nameIdentifiers'])) {
            $nameIds = array_map(fn (array $ni): array => [
                'attrs' => $this->filterAttributes([
                    'nameIdentifierScheme' => $ni['nameIdentifierScheme'] ?? null,
                    'schemeUri' => $ni['schemeUri'] ?? null,
                ]),
                'value' => $ni['nameIdentifier'],
            ], $creator['nameIdentifiers']);
            $result['nameIdentifier'] = count($nameIds) === 1 ? $nameIds[0] : $nameIds;
        }

        // Affiliations
        if (! empty($creator['affiliation'])) {
            $affiliations = array_map(fn (array $aff): array => $this->transformAffiliation($aff), $creator['affiliation']);
            $result['affiliation'] = count($affiliations) === 1 ? $affiliations[0] : $affiliations;
        }

        return $result;
    }

    /**
     * @param  array<string, string|null>  $affiliation
     * @return array<string, mixed>
     */
    private function transformAffiliation(array $affiliation): array
    {
        $attrs = $this->filterAttributes([
            'affiliationIdentifier' => $affiliation['affiliationIdentifier'] ?? null,
            'affiliationIdentifierScheme' => $affiliation['affiliationIdentifierScheme'] ?? null,
            'schemeURI' => $affiliation['schemeUri'] ?? null,
        ]);

        $result = ['value' => $affiliation['name'] ?? ''];
        if (! empty($attrs)) {
            $result['attrs'] = $attrs;
        }

        return $result;
    }

    /**
     * @param  array<int, array<string, mixed>>  $titles
     * @return array<string, mixed>
     */
    private function transformTitles(array $titles): array
    {
        $transformed = array_map(function (array $title): array {
            $attrs = $this->filterAttributes([
                'titleType' => $title['titleType'] ?? null,
                'lang' => $title['lang'] ?? null,
            ]);

            $result = ['value' => $title['title']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $titles);

        return ['title' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<string, string|null>  $publisher
     * @return array<string, mixed>
     */
    private function transformPublisher(array $publisher): array
    {
        $attrs = $this->filterAttributes([
            'publisherIdentifier' => $publisher['publisherIdentifier'] ?? null,
            'publisherIdentifierScheme' => $publisher['publisherIdentifierScheme'] ?? null,
            'schemeUri' => $publisher['schemeUri'] ?? null,
            'lang' => $publisher['lang'] ?? null,
        ]);

        $result = ['value' => $publisher['name']];
        if (! empty($attrs)) {
            $result['attrs'] = $attrs;
        }

        return $result;
    }

    /**
     * @param  array<string, string>  $types
     * @return array<string, mixed>
     */
    private function transformResourceType(array $types): array
    {
        return [
            'attrs' => ['resourceTypeGeneral' => $types['resourceTypeGeneral']],
            'value' => $types['resourceType'] ?? '',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $subjects
     * @return array<string, mixed>
     */
    private function transformSubjects(array $subjects): array
    {
        $transformed = array_map(function (array $subject): array {
            $attrs = $this->filterAttributes([
                'subjectScheme' => $subject['subjectScheme'] ?? null,
                'schemeUri' => $subject['schemeUri'] ?? null,
                'valueUri' => $subject['valueUri'] ?? null,
                'classificationCode' => $subject['classificationCode'] ?? null,
                'lang' => $subject['lang'] ?? null,
            ]);

            $result = ['value' => $subject['subject']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $subjects);

        return ['subject' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $contributors
     * @return array<string, mixed>
     */
    private function transformContributors(array $contributors): array
    {
        $transformed = array_map(function (array $contributor): array {
            $result = [];

            // Contributor type as attribute
            $attrs = [];
            if (isset($contributor['contributorType'])) {
                $attrs['contributorType'] = $contributor['contributorType'];
            }

            // Contributor name with nameType
            $nameAttrs = [];
            if (isset($contributor['nameType'])) {
                $nameAttrs['nameType'] = $contributor['nameType'];
            }
            $result['contributorName'] = [
                'attrs' => $nameAttrs,
                'value' => $contributor['name'],
            ];

            // Given name
            if (isset($contributor['givenName'])) {
                $result['givenName'] = ['value' => $contributor['givenName']];
            }

            // Family name
            if (isset($contributor['familyName'])) {
                $result['familyName'] = ['value' => $contributor['familyName']];
            }

            // Name identifiers
            if (! empty($contributor['nameIdentifiers'])) {
                $nameIds = array_map(fn (array $ni): array => [
                    'attrs' => $this->filterAttributes([
                        'nameIdentifierScheme' => $ni['nameIdentifierScheme'] ?? null,
                        'schemeUri' => $ni['schemeUri'] ?? null,
                    ]),
                    'value' => $ni['nameIdentifier'],
                ], $contributor['nameIdentifiers']);
                $result['nameIdentifier'] = count($nameIds) === 1 ? $nameIds[0] : $nameIds;
            }

            // Affiliations
            if (! empty($contributor['affiliation'])) {
                $affiliations = array_map(fn (array $aff): array => $this->transformAffiliation($aff), $contributor['affiliation']);
                $result['affiliation'] = count($affiliations) === 1 ? $affiliations[0] : $affiliations;
            }

            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $contributors);

        return ['contributor' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $dates
     * @return array<string, mixed>
     */
    private function transformDates(array $dates): array
    {
        $transformed = array_map(function (array $date): array {
            $attrs = $this->filterAttributes([
                'dateType' => $date['dateType'] ?? null,
                'dateInformation' => $date['dateInformation'] ?? null,
            ]);

            $result = ['value' => $date['date']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $dates);

        return ['date' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, string>>  $alternateIdentifiers
     * @return array<string, mixed>
     */
    private function transformAlternateIdentifiers(array $alternateIdentifiers): array
    {
        $transformed = array_map(fn (array $altId): array => [
            'attrs' => ['alternateIdentifierType' => $altId['alternateIdentifierType']],
            'value' => $altId['alternateIdentifier'],
        ], $alternateIdentifiers);

        return ['alternateIdentifier' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     * @return array<string, mixed>
     */
    private function transformRelatedIdentifiers(array $relatedIdentifiers): array
    {
        $transformed = array_map(function (array $ri): array {
            $attrs = $this->filterAttributes([
                'relatedIdentifierType' => $ri['relatedIdentifierType'] ?? null,
                'relationType' => $ri['relationType'] ?? null,
                'relatedMetadataScheme' => $ri['relatedMetadataScheme'] ?? null,
                'schemeURI' => $ri['schemeUri'] ?? null,
                'schemeType' => $ri['schemeType'] ?? null,
                'resourceTypeGeneral' => $ri['resourceTypeGeneral'] ?? null,
                'relationTypeInformation' => $ri['relationTypeInformation'] ?? null,
            ]);

            $result = ['value' => $ri['relatedIdentifier']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $relatedIdentifiers);

        return ['relatedIdentifier' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $relatedItems
     * @return array<string, mixed>
     */
    private function transformRelatedItems(array $relatedItems): array
    {
        $transformed = array_map(function (array $ri): array {
            $attrs = $this->filterAttributes([
                'relatedItemType' => $ri['relatedItemType'] ?? null,
                'relationType' => $ri['relationType'] ?? null,
                'relationTypeInformation' => $ri['relationTypeInformation'] ?? null,
            ]);

            $value = [];

            if (! empty($ri['titles'])) {
                $value['titles'] = $this->transformTitles($ri['titles']);
            }
            if (! empty($ri['creators'])) {
                $value['creators'] = $this->transformCreators($ri['creators']);
            }
            if (! empty($ri['contributors'])) {
                $value['contributors'] = $this->transformContributors($ri['contributors']);
            }
            if (isset($ri['relatedItemIdentifier']) && is_array($ri['relatedItemIdentifier'])) {
                $identifier = $ri['relatedItemIdentifier'];
                $identifierAttrs = $this->filterAttributes([
                    'relatedItemIdentifierType' => $identifier['relatedItemIdentifierType'] ?? null,
                    'relatedMetadataScheme' => $identifier['relatedMetadataScheme'] ?? null,
                    'schemeURI' => $identifier['schemeUri'] ?? null,
                    'schemeType' => $identifier['schemeType'] ?? null,
                ]);
                $value['relatedItemIdentifier'] = [
                    'attrs' => $identifierAttrs,
                    'value' => $identifier['relatedItemIdentifier'] ?? '',
                ];
            }

            foreach (['publicationYear', 'volume', 'issue', 'firstPage', 'lastPage', 'publisher', 'edition'] as $key) {
                if (array_key_exists($key, $ri)) {
                    $value[$key] = ['value' => $ri[$key]];
                }
            }
            if (array_key_exists('number', $ri)) {
                $value['number'] = [
                    'attrs' => $this->filterAttributes(['numberType' => $ri['numberType'] ?? null]),
                    'value' => $ri['number'],
                ];
            }

            $result = [];
            if ($attrs !== []) {
                $result['attrs'] = $attrs;
            }
            if ($value !== []) {
                $result['value'] = $value;
            }

            return $result;
        }, $relatedItems);

        return ['relatedItem' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  list<string>  $sizes
     * @return array<string, mixed>
     */
    private function transformSizes(array $sizes): array
    {
        $transformed = array_map(fn (string $size): array => ['value' => $size], $sizes);

        return ['size' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  list<string>  $formats
     * @return array<string, mixed>
     */
    private function transformFormats(array $formats): array
    {
        $transformed = array_map(fn (string $format): array => ['value' => $format], $formats);

        return ['format' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, string>>  $rightsList
     * @return array<string, mixed>
     */
    private function transformRightsList(array $rightsList): array
    {
        $transformed = array_map(function (array $rights): array {
            $attrs = $this->filterAttributes([
                'rightsURI' => $rights['rightsUri'] ?? null,
                'rightsIdentifier' => $rights['rightsIdentifier'] ?? null,
                'rightsIdentifierScheme' => $rights['rightsIdentifierScheme'] ?? null,
                'schemeURI' => $rights['schemeUri'] ?? null,
                'lang' => $rights['lang'] ?? null,
            ]);

            $result = ['value' => $rights['rights']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $rightsList);

        return ['rights' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $descriptions
     * @return array<string, mixed>
     */
    private function transformDescriptions(array $descriptions): array
    {
        $transformed = array_map(function (array $desc): array {
            $attrs = $this->filterAttributes([
                'descriptionType' => $desc['descriptionType'] ?? null,
                'lang' => $desc['lang'] ?? null,
            ]);

            $result = ['value' => $desc['description']];
            if (! empty($attrs)) {
                $result['attrs'] = $attrs;
            }

            return $result;
        }, $descriptions);

        return ['description' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $geoLocations
     * @return array<string, mixed>
     */
    private function transformGeoLocations(array $geoLocations): array
    {
        $transformed = array_map(function (array $geo): array {
            $result = [];

            if (isset($geo['geoLocationPlace'])) {
                $result['geoLocationPlace'] = ['value' => $geo['geoLocationPlace']];
            }

            if (isset($geo['geoLocationPoint'])) {
                $result['geoLocationPoint'] = [
                    'pointLongitude' => ['value' => (string) $geo['geoLocationPoint']['pointLongitude']],
                    'pointLatitude' => ['value' => (string) $geo['geoLocationPoint']['pointLatitude']],
                ];
            }

            if (isset($geo['geoLocationBox'])) {
                $box = $geo['geoLocationBox'];
                $result['geoLocationBox'] = [
                    'westBoundLongitude' => ['value' => (string) $box['westBoundLongitude']],
                    'eastBoundLongitude' => ['value' => (string) $box['eastBoundLongitude']],
                    'southBoundLatitude' => ['value' => (string) $box['southBoundLatitude']],
                    'northBoundLatitude' => ['value' => (string) $box['northBoundLatitude']],
                ];
            }

            if (isset($geo['geoLocationPolygon'])) {
                $polygon = $geo['geoLocationPolygon'];
                $points = [];
                $inPolygonPoint = null;

                foreach ($polygon as $entry) {
                    if (isset($entry['polygonPoint'])) {
                        $points[] = [
                            'pointLongitude' => ['value' => (string) $entry['polygonPoint']['pointLongitude']],
                            'pointLatitude' => ['value' => (string) $entry['polygonPoint']['pointLatitude']],
                        ];
                    }

                    if (isset($entry['inPolygonPoint'])) {
                        $inPolygonPoint = [
                            'pointLongitude' => ['value' => (string) $entry['inPolygonPoint']['pointLongitude']],
                            'pointLatitude' => ['value' => (string) $entry['inPolygonPoint']['pointLatitude']],
                        ];
                    }
                }

                $result['geoLocationPolygon'] = ['polygonPoint' => $points];

                if ($inPolygonPoint !== null) {
                    $result['geoLocationPolygon']['inPolygonPoint'] = $inPolygonPoint;
                }
            }

            return $result;
        }, $geoLocations);

        return ['geoLocation' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fundingReferences
     * @return array<string, mixed>
     */
    private function transformFundingReferences(array $fundingReferences): array
    {
        $transformed = array_map(function (array $funding): array {
            $result = [
                'funderName' => ['value' => $funding['funderName']],
            ];

            if (isset($funding['funderIdentifier'])) {
                $attrs = $this->filterAttributes([
                    'funderIdentifierType' => $funding['funderIdentifierType'] ?? null,
                    'schemeUri' => $funding['schemeUri'] ?? null,
                ]);
                $funderIdEntry = ['value' => $funding['funderIdentifier']];
                if (! empty($attrs)) {
                    $funderIdEntry['attrs'] = $attrs;
                }
                $result['funderIdentifier'] = $funderIdEntry;
            }

            if (isset($funding['awardNumber'])) {
                $awardEntry = ['value' => $funding['awardNumber']];
                if (isset($funding['awardUri'])) {
                    $awardEntry['attrs'] = ['awardUri' => $funding['awardUri']];
                }
                $result['awardNumber'] = $awardEntry;
            }

            if (isset($funding['awardTitle'])) {
                $result['awardTitle'] = ['value' => $funding['awardTitle']];
            }

            return $result;
        }, $fundingReferences);

        return ['fundingReference' => count($transformed) === 1 ? $transformed[0] : $transformed];
    }
}
