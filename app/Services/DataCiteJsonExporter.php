<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;

/**
 * Service for exporting Resource data to DataCite JSON format (v4.5/4.6)
 *
 * Implements the DataCite Metadata Schema v4.5 JSON format with v4.6 extensions.
 * Schema: https://github.com/inveniosoftware/datacite/blob/master/datacite/schemas/datacite-v4.5.json
 * Documentation: https://datacite-metadata-schema.readthedocs.io/en/4.6/
 *
 * DataCite 4.6 additions over 4.5:
 * - resourceTypeGeneral: Award, Project
 * - relatedIdentifierType: CSTR, RRID
 * - contributorType: Translator
 * - relationType: HasTranslation, IsTranslationOf
 * - dateType: Coverage
 */
class DataCiteJsonExporter
{
    /**
     * Export a Resource to DataCite JSON format
     *
     * @param  resource  $resource  The resource to export
     * @return array<string, mixed> The DataCite JSON structure
     */
    public function export(Resource $resource): array
    {
        // Load all necessary relationships
        $resource->load([
            'resourceType',
            'language',
            'publisher',
            'titles.titleType',
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorType',
            'contributors.affiliations',
            'descriptions.descriptionType',
            'dates.dateType',
            'subjects',
            'geoLocations.polygons',
            'rights',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'fundingReferences.funderIdentifierType',
        ]);

        return [
            'data' => [
                'type' => 'dois',
                'attributes' => $this->buildAttributes($resource),
            ],
        ];
    }

    /**
     * Build the attributes section of the DataCite JSON
     *
     * @return array<string, mixed>
     */
    private function buildAttributes(Resource $resource): array
    {
        $attributes = [
            'doi' => $resource->doi,
            'titles' => $this->buildTitles($resource),
            'publisher' => $this->buildPublisher($resource),
            'publicationYear' => $resource->publication_year,
            'types' => $this->buildTypes($resource),
            'creators' => $this->buildCreators($resource),
        ];

        // Add optional fields only if they have data
        if ($contributors = $this->buildContributors($resource)) {
            $attributes['contributors'] = $contributors;
        }

        if ($subjects = $this->buildSubjects($resource)) {
            $attributes['subjects'] = $subjects;
        }

        if ($descriptions = $this->buildDescriptions($resource)) {
            $attributes['descriptions'] = $descriptions;
        }

        if ($dates = $this->buildDates($resource)) {
            $attributes['dates'] = $dates;
        }

        if ($resource->language) {
            $attributes['language'] = $resource->language->iso_code ?? 'en';
        }

        if ($resource->version) {
            $attributes['version'] = $resource->version;
        }

        if ($rightsList = $this->buildRightsList($resource)) {
            $attributes['rightsList'] = $rightsList;
        }

        if ($geoLocations = $this->buildGeoLocations($resource)) {
            $attributes['geoLocations'] = $geoLocations;
        }

        if ($relatedIdentifiers = $this->buildRelatedIdentifiers($resource)) {
            $attributes['relatedIdentifiers'] = $relatedIdentifiers;
        }

        if ($fundingReferences = $this->buildFundingReferences($resource)) {
            $attributes['fundingReferences'] = $fundingReferences;
        }

        return $attributes;
    }

    /**
     * Build titles array
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildTitles(Resource $resource): array
    {
        $titles = [];

        foreach ($resource->titles as $title) {
            $titleData = [
                'title' => $title->value,
            ];

            // Map title types to DataCite format
            $titleType = $title->titleType?->slug;
            if ($titleType && $titleType !== 'main-title') {
                // Convert slug format to DataCite TitleCase format
                $titleData['titleType'] = $this->convertTitleType($titleType);
            }

            // Add language if available
            if ($resource->language) {
                $titleData['lang'] = $resource->language->iso_code ?? 'en';
            }

            $titles[] = $titleData;
        }

        return $titles;
    }

    /**
     * Convert title type slug to DataCite format
     */
    private function convertTitleType(string $slug): string
    {
        $mapping = [
            'subtitle' => 'Subtitle',
            'alternative-title' => 'AlternativeTitle',
            'translated-title' => 'TranslatedTitle',
            'other' => 'Other',
        ];

        return $mapping[$slug] ?? 'Other';
    }

    /**
     * Build publisher information
     *
     * @return array<string, string|null>
     */
    private function buildPublisher(Resource $resource): array
    {
        $publisher = $resource->publisher;

        if (! $publisher) {
            return ['name' => 'GFZ Data Services'];
        }

        $data = [
            'name' => $publisher->name,
        ];

        if ($publisher->identifier) {
            $data['publisherIdentifier'] = $publisher->identifier;
            $data['publisherIdentifierScheme'] = $publisher->identifier_scheme ?? 'ROR';
            if ($publisher->scheme_uri) {
                $data['schemeUri'] = $publisher->scheme_uri;
            }
        }

        return $data;
    }

    /**
     * Build types (resource type) information
     *
     * @return array<string, string>
     */
    private function buildTypes(Resource $resource): array
    {
        $resourceType = $resource->resourceType;

        return [
            'resourceTypeGeneral' => $resourceType->name ?? 'Other',
            'resourceType' => $resourceType->name ?? 'Other',
        ];
    }

    /**
     * Build creators array from authors
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCreators(Resource $resource): array
    {
        $creators = [];

        foreach ($resource->creators as $creator) {
            if ($creator->creatorable_type === Person::class) {
                $creators[] = $this->buildPersonCreator($creator);
            } elseif ($creator->creatorable_type === Institution::class) {
                $creators[] = $this->buildInstitutionCreator($creator);
            }
        }

        // If no authors, return at least an empty creator to satisfy schema requirements
        if (empty($creators)) {
            $creators[] = [
                'name' => 'Unknown',
                'nameType' => 'Personal',
            ];
        }

        return $creators;
    }

    /**
     * Build a person creator entry
     *
     * @return array<string, mixed>
     */
    private function buildPersonCreator(ResourceCreator $creator): array
    {
        /** @var Person|null $person */
        $person = $creator->creatorable;

        if (! $person instanceof Person) {
            return [
                'name' => 'Unknown',
                'nameType' => 'Personal',
            ];
        }

        $creatorData = [
            'nameType' => 'Personal',
        ];

        // Build name in "FamilyName, GivenName" format
        if ($person->family_name && $person->given_name) {
            $creatorData['name'] = "{$person->family_name}, {$person->given_name}";
            $creatorData['givenName'] = $person->given_name;
            $creatorData['familyName'] = $person->family_name;
        } elseif ($person->family_name) {
            $creatorData['name'] = $person->family_name;
            $creatorData['familyName'] = $person->family_name;
        } elseif ($person->given_name) {
            $creatorData['name'] = $person->given_name;
            $creatorData['givenName'] = $person->given_name;
        } else {
            $creatorData['name'] = 'Unknown';
        }

        // Add name identifier (ORCID) if available
        if ($person->name_identifier) {
            $creatorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $person->name_identifier,
                    'nameIdentifierScheme' => $person->name_identifier_scheme ?? 'ORCID',
                    'schemeUri' => 'https://orcid.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($creator)) {
            $creatorData['affiliation'] = $affiliations;
        }

        return $creatorData;
    }

    /**
     * Build an institution creator entry
     *
     * @return array<string, mixed>
     */
    private function buildInstitutionCreator(ResourceCreator $creator): array
    {
        /** @var Institution|null $institution */
        $institution = $creator->creatorable;

        if (! $institution instanceof Institution) {
            return [
                'name' => 'Unknown Institution',
                'nameType' => 'Organizational',
            ];
        }

        $creatorData = [
            'name' => $institution->name ?? 'Unknown Institution',
            'nameType' => 'Organizational',
        ];

        // Add name identifier (ROR) if available
        if ($institution->name_identifier) {
            $creatorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->name_identifier,
                    'nameIdentifierScheme' => $institution->name_identifier_scheme ?? 'ROR',
                    'schemeUri' => 'https://ror.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($creator)) {
            $creatorData['affiliation'] = $affiliations;
        }

        return $creatorData;
    }

    /**
     * Build affiliations for a person or institution
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildAffiliations(ResourceCreator|ResourceContributor $author): ?array
    {
        $affiliations = [];

        foreach ($author->affiliations as $affiliation) {
            $affiliationData = [
                'name' => $affiliation->name,
            ];

            if ($affiliation->identifier) {
                $affiliationData['affiliationIdentifier'] = $affiliation->identifier;
                $affiliationData['affiliationIdentifierScheme'] = $affiliation->identifier_scheme ?? 'ROR';
                if ($affiliation->scheme_uri) {
                    $affiliationData['schemeUri'] = $affiliation->scheme_uri;
                }
            }

            $affiliations[] = $affiliationData;
        }

        return ! empty($affiliations) ? $affiliations : null;
    }

    /**
     * Build contributors array (excluding authors, including MSL labs)
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildContributors(Resource $resource): ?array
    {
        $contributors = [];

        foreach ($resource->contributors as $contributor) {
            // Check if this is an MSL Laboratory
            if ($contributor->contributorable_type === Institution::class) {
                /** @var Institution|null $institution */
                $institution = $contributor->contributorable;
                if ($institution instanceof Institution && $institution->isLaboratory()) {
                    // MSL Laboratory - add as HostingInstitution
                    $contributors[] = $this->buildMslLaboratoryContributor($contributor);

                    continue;
                }
            }

            // Regular contributor - use contributorType from lookup table
            $contributorType = $contributor->contributorType->name ?? 'Other';

            if ($contributor->contributorable_type === Person::class) {
                $contributors[] = $this->buildPersonContributor($contributor, $contributorType);
            } elseif ($contributor->contributorable_type === Institution::class) {
                $contributors[] = $this->buildInstitutionContributor($contributor, $contributorType);
            }
        }

        return ! empty($contributors) ? $contributors : null;
    }

    /**
     * Build MSL Laboratory contributor
     *
     * @return array<string, mixed>
     */
    private function buildMslLaboratoryContributor(ResourceContributor $contributor): array
    {
        /** @var Institution|null $institution */
        $institution = $contributor->contributorable;

        if (! $institution instanceof Institution) {
            return [
                'name' => 'Unknown Laboratory',
                'nameType' => 'Organizational',
                'contributorType' => 'HostingInstitution',
            ];
        }

        $contributorData = [
            'name' => $institution->name ?? 'Unknown Laboratory',
            'nameType' => 'Organizational',
            'contributorType' => 'HostingInstitution',
        ];

        // Add laboratory identifier if available
        if ($institution->name_identifier) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->name_identifier,
                    'nameIdentifierScheme' => $institution->name_identifier_scheme ?? 'labid',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($contributor)) {
            $contributorData['affiliation'] = $affiliations;
        }

        return $contributorData;
    }

    /**
     * Build a person contributor entry
     *
     * @return array<string, mixed>
     */
    private function buildPersonContributor(ResourceContributor $contributor, string $contributorType): array
    {
        /** @var Person|null $person */
        $person = $contributor->contributorable;

        if (! $person instanceof Person) {
            return [
                'name' => 'Unknown',
                'nameType' => 'Personal',
                'contributorType' => $contributorType,
            ];
        }

        $contributorData = [
            'nameType' => 'Personal',
            'contributorType' => $contributorType,
        ];

        // Build name
        if ($person->family_name && $person->given_name) {
            $contributorData['name'] = "{$person->family_name}, {$person->given_name}";
            $contributorData['givenName'] = $person->given_name;
            $contributorData['familyName'] = $person->family_name;
        } elseif ($person->family_name) {
            $contributorData['name'] = $person->family_name;
            $contributorData['familyName'] = $person->family_name;
        } elseif ($person->given_name) {
            $contributorData['name'] = $person->given_name;
            $contributorData['givenName'] = $person->given_name;
        } else {
            $contributorData['name'] = 'Unknown';
        }

        // Add name identifier (ORCID) if available
        if ($person->name_identifier) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $person->name_identifier,
                    'nameIdentifierScheme' => $person->name_identifier_scheme ?? 'ORCID',
                    'schemeUri' => 'https://orcid.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($contributor)) {
            $contributorData['affiliation'] = $affiliations;
        }

        return $contributorData;
    }

    /**
     * Build an institution contributor entry
     *
     * @return array<string, mixed>
     */
    private function buildInstitutionContributor(ResourceContributor $contributor, string $contributorType): array
    {
        /** @var Institution|null $institution */
        $institution = $contributor->contributorable;

        if (! $institution instanceof Institution) {
            return [
                'name' => 'Unknown Institution',
                'nameType' => 'Organizational',
                'contributorType' => $contributorType,
            ];
        }

        $contributorData = [
            'name' => $institution->name ?? 'Unknown Institution',
            'nameType' => 'Organizational',
            'contributorType' => $contributorType,
        ];

        // Add name identifier (ROR) if available
        if ($institution->name_identifier) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->name_identifier,
                    'nameIdentifierScheme' => $institution->name_identifier_scheme ?? 'ROR',
                    'schemeUri' => 'https://ror.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($contributor)) {
            $contributorData['affiliation'] = $affiliations;
        }

        return $contributorData;
    }

    /**
     * Build subjects array from subjects table
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildSubjects(Resource $resource): ?array
    {
        $subjects = [];

        foreach ($resource->subjects as $subject) {
            $subjectData = [
                'subject' => $subject->value,
            ];

            if ($subject->subject_scheme) {
                $subjectData['subjectScheme'] = $subject->subject_scheme;
            }

            if ($subject->scheme_uri) {
                $subjectData['schemeUri'] = $subject->scheme_uri;
            }

            if ($subject->value_uri) {
                $subjectData['valueUri'] = $subject->value_uri;
            }

            if ($subject->classification_code) {
                $subjectData['classificationCode'] = $subject->classification_code;
            }

            $subjects[] = $subjectData;
        }

        return ! empty($subjects) ? $subjects : null;
    }

    /**
     * Build descriptions array
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildDescriptions(Resource $resource): ?array
    {
        $descriptions = [];

        foreach ($resource->descriptions as $description) {
            $descriptionData = [
                'description' => $description->value,
                'descriptionType' => $description->descriptionType->slug ?? 'Other',
            ];

            // Add language if available
            if ($resource->language) {
                $descriptionData['lang'] = $resource->language->iso_code ?? 'en';
            }

            $descriptions[] = $descriptionData;
        }

        return ! empty($descriptions) ? $descriptions : null;
    }

    /**
     * Build dates array
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildDates(Resource $resource): ?array
    {
        $dates = [];

        foreach ($resource->dates as $date) {
            // Skip if no date type (should not happen in normal usage)
            // @phpstan-ignore booleanNot.alwaysFalse
            if (! $date->dateType) {
                continue;
            }

            // Format date value according to DataCite schema:
            // - Single date: use date_value or start_date
            // - Closed range: start_date/end_date
            // - Open-ended range (start_date only, null end_date): exported as single date
            //
            // Note on open-ended ranges: While RKMS-ISO8601 allows "YYYY-MM-DD/" format for
            // open-ended ranges, DataCite's schema and API validation treat dates without
            // an end component as single dates. Exporting "2020-01-01/" would fail validation.
            // Therefore, open-ended ranges are intentionally exported as single dates.
            // The range information is preserved in the database for potential future use.
            $dateValue = null;
            if ($date->isRange()) {
                // Closed range with both dates
                $dateValue = $date->start_date . '/' . $date->end_date;
            } elseif ($date->isOpenEndedRange()) {
                // Open-ended range - exported as single date (DataCite doesn't support trailing slash)
                $dateValue = $date->start_date;
            } else {
                // Single date
                $dateValue = $date->date_value ?? $date->start_date;
            }

            // Skip dates where no value could be determined
            if ($dateValue === null || $dateValue === '') {
                continue;
            }

            $dateData = [
                'dateType' => $date->dateType->name,
                'date' => $dateValue,
            ];

            // Add date information if available
            if ($date->date_information) {
                $dateData['dateInformation'] = $date->date_information;
            }

            $dates[] = $dateData;
        }

        return ! empty($dates) ? $dates : null;
    }

    /**
     * Build rights list from rights table
     *
     * @return array<int, array<string, string>>|null
     */
    private function buildRightsList(Resource $resource): ?array
    {
        $rightsList = [];

        foreach ($resource->rights as $right) {
            $rightsData = [
                'rights' => $right->name,
            ];

            // Add rightsURI (license reference URL)
            if ($right->uri) {
                $rightsData['rightsURI'] = $right->uri;
            }

            // Add rights identifier (SPDX)
            if ($right->identifier) {
                $rightsData['rightsIdentifier'] = $right->identifier;
                $rightsData['rightsIdentifierScheme'] = 'SPDX';
                if ($right->scheme_uri) {
                    $rightsData['schemeURI'] = $right->scheme_uri;
                }
            }

            // Add language if available
            if ($resource->language) {
                $rightsData['lang'] = $resource->language->iso_code ?? 'en';
            }

            $rightsList[] = $rightsData;
        }

        return ! empty($rightsList) ? $rightsList : null;
    }

    /**
     * Build geo locations from geoLocations table
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildGeoLocations(Resource $resource): ?array
    {
        $geoLocationsData = [];

        foreach ($resource->geoLocations as $geoLocation) {
            $geoLocationData = [];

            // Add place name
            if ($geoLocation->place) {
                $geoLocationData['geoLocationPlace'] = $geoLocation->place;
            }

            // Add point if available
            if ($geoLocation->point_longitude !== null && $geoLocation->point_latitude !== null) {
                $geoLocationData['geoLocationPoint'] = [
                    'pointLongitude' => $geoLocation->point_longitude,
                    'pointLatitude' => $geoLocation->point_latitude,
                ];
            }

            // Add bounding box if available
            if ($geoLocation->west_bound_longitude !== null &&
                $geoLocation->east_bound_longitude !== null &&
                $geoLocation->south_bound_latitude !== null &&
                $geoLocation->north_bound_latitude !== null) {
                $geoLocationData['geoLocationBox'] = [
                    'westBoundLongitude' => $geoLocation->west_bound_longitude,
                    'eastBoundLongitude' => $geoLocation->east_bound_longitude,
                    'southBoundLatitude' => $geoLocation->south_bound_latitude,
                    'northBoundLatitude' => $geoLocation->north_bound_latitude,
                ];
            }

            // Add polygons if available
            if ($geoLocation->polygons->isNotEmpty()) {
                // Collect all polygon points grouped by geo_location_id
                // First, collect regular points
                $regularPoints = $geoLocation->polygons
                    ->where('is_in_polygon_point', false)
                    ->sortBy('position')
                    ->map(fn ($p) => [
                        'pointLongitude' => $p->point_longitude,
                        'pointLatitude' => $p->point_latitude,
                    ])
                    ->values()
                    ->all();

                // Get the inPolygonPoint if exists
                $inPoint = $geoLocation->polygons->firstWhere('is_in_polygon_point', true);

                if (! empty($regularPoints)) {
                    $polygonData = [
                        'polygonPoints' => $regularPoints,
                    ];
                    if ($inPoint !== null) {
                        $polygonData['inPolygonPoint'] = [
                            'pointLongitude' => $inPoint->point_longitude,
                            'pointLatitude' => $inPoint->point_latitude,
                        ];
                    }
                    $geoLocationData['geoLocationPolygon'] = $polygonData;
                }
            }

            if (! empty($geoLocationData)) {
                $geoLocationsData[] = $geoLocationData;
            }
        }

        return ! empty($geoLocationsData) ? $geoLocationsData : null;
    }

    /**
     * Build related identifiers array
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildRelatedIdentifiers(Resource $resource): ?array
    {
        $relatedIdentifiers = [];

        foreach ($resource->relatedIdentifiers as $relatedIdentifier) {
            $relatedData = [
                'relatedIdentifier' => $relatedIdentifier->identifier,
                'relatedIdentifierType' => $relatedIdentifier->identifierType->name ?? 'DOI',
                'relationType' => $relatedIdentifier->relationType->name ?? 'References',
            ];

            // Add resource type general if available
            if ($relatedIdentifier->resource_type_general) {
                $relatedData['resourceTypeGeneral'] = $relatedIdentifier->resource_type_general;
            }

            $relatedIdentifiers[] = $relatedData;
        }

        return ! empty($relatedIdentifiers) ? $relatedIdentifiers : null;
    }

    /**
     * Build funding references array
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildFundingReferences(Resource $resource): ?array
    {
        $fundingReferences = [];

        foreach ($resource->fundingReferences as $funding) {
            $fundingData = [
                'funderName' => $funding->funder_name,
            ];

            if ($funding->funder_identifier) {
                $fundingData['funderIdentifier'] = $funding->funder_identifier;
                $fundingData['funderIdentifierType'] = $funding->funderIdentifierType->name ?? 'Other';
            }

            if ($funding->scheme_uri) {
                $fundingData['schemeUri'] = $funding->scheme_uri;
            }

            if ($funding->award_number) {
                $fundingData['awardNumber'] = $funding->award_number;
            }

            if ($funding->award_uri) {
                $fundingData['awardUri'] = $funding->award_uri;
            }

            if ($funding->award_title) {
                $fundingData['awardTitle'] = $funding->award_title;
            }

            $fundingReferences[] = $fundingData;
        }

        return ! empty($fundingReferences) ? $fundingReferences : null;
    }
}
