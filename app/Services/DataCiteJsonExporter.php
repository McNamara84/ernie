<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceAuthor;

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
     * Fixed publisher information for all exports
     */
    private const PUBLISHER_NAME = 'GFZ Helmholtz Centre for Geosciences';
    private const PUBLISHER_ROR_ID = 'https://ror.org/04z8jg394';

    /**
     * Export a Resource to DataCite JSON format
     *
     * @param Resource $resource The resource to export
     * @return array<string, mixed> The DataCite JSON structure
     */
    public function export(Resource $resource): array
    {
        // Load all necessary relationships
        $resource->load([
            'resourceType',
            'language',
            'titles.titleType',
            'authors.authorable',
            'authors.roles',
            'authors.affiliations',
            'contributors.authorable',
            'contributors.roles',
            'contributors.affiliations',
            'descriptions',
            'dates',
            'keywords',
            'controlledKeywords',
            'coverages',
            'licenses',
            'relatedIdentifiers',
            'fundingReferences',
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
     * @param Resource $resource
     * @return array<string, mixed>
     */
    private function buildAttributes(Resource $resource): array
    {
        $attributes = [
            'doi' => $resource->doi,
            'titles' => $this->buildTitles($resource),
            'publisher' => $this->buildPublisher(),
            'publicationYear' => $resource->year,
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
     * @param Resource $resource
     * @return array<int, array<string, mixed>>
     */
    private function buildTitles(Resource $resource): array
    {
        $titles = [];

        foreach ($resource->titles as $title) {
            $titleData = [
                'title' => $title->title,
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
     *
     * @param string $slug
     * @return string
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
     * @return array<string, string>
     */
    private function buildPublisher(): array
    {
        return [
            'name' => self::PUBLISHER_NAME,
            'publisherIdentifier' => self::PUBLISHER_ROR_ID,
            'publisherIdentifierScheme' => 'ROR',
            'schemeUri' => 'https://ror.org/',
        ];
    }

    /**
     * Build types (resource type) information
     *
     * @param Resource $resource
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
     * @param Resource $resource
     * @return array<int, array<string, mixed>>
     */
    private function buildCreators(Resource $resource): array
    {
        $creators = [];

        foreach ($resource->authors as $author) {
            if ($author->authorable_type === Person::class) {
                $creators[] = $this->buildPersonCreator($author);
            } elseif ($author->authorable_type === Institution::class) {
                $creators[] = $this->buildInstitutionCreator($author);
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
     * @param ResourceAuthor $author
     * @return array<string, mixed>
     */
    private function buildPersonCreator(ResourceAuthor $author): array
    {
        /** @var Person|null $person */
        $person = $author->authorable;
        
        if (!$person instanceof Person) {
            return [
                'name' => 'Unknown',
                'nameType' => 'Personal',
            ];
        }
        
        $creator = [
            'nameType' => 'Personal',
        ];

        // Build name in "FamilyName, GivenName" format
        if ($person->last_name && $person->first_name) {
            $creator['name'] = "{$person->last_name}, {$person->first_name}";
            $creator['givenName'] = $person->first_name;
            $creator['familyName'] = $person->last_name;
        } elseif ($person->last_name) {
            $creator['name'] = $person->last_name;
            $creator['familyName'] = $person->last_name;
        } elseif ($person->first_name) {
            $creator['name'] = $person->first_name;
            $creator['givenName'] = $person->first_name;
        } else {
            $creator['name'] = 'Unknown';
        }

        // Add ORCID if available
        if ($person->orcid) {
            $creator['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $person->orcid,
                    'nameIdentifierScheme' => 'ORCID',
                    'schemeUri' => 'https://orcid.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($author)) {
            $creator['affiliation'] = $affiliations;
        }

        return $creator;
    }

    /**
     * Build an institution creator entry
     *
     * @param ResourceAuthor $author
     * @return array<string, mixed>
     */
    private function buildInstitutionCreator(ResourceAuthor $author): array
    {
        /** @var Institution|null $institution */
        $institution = $author->authorable;
        
        if (!$institution instanceof Institution) {
            return [
                'name' => 'Unknown Institution',
                'nameType' => 'Organizational',
            ];
        }
        
        $creator = [
            'name' => $institution->name ?? 'Unknown Institution',
            'nameType' => 'Organizational',
        ];

        // Add ROR identifier if available
        if ($institution->identifier_type === 'ROR' && $institution->identifier) {
            $creator['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->identifier,
                    'nameIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org',
                ],
            ];
        } elseif ($institution->ror_id) {
            // Legacy ROR field
            $creator['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->ror_id,
                    'nameIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org',
                ],
            ];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($author)) {
            $creator['affiliation'] = $affiliations;
        }

        return $creator;
    }

    /**
     * Build affiliations for a person or institution
     *
     * @param ResourceAuthor $author
     * @return array<int, array<string, mixed>>|null
     */
    private function buildAffiliations(ResourceAuthor $author): ?array
    {
        $affiliations = [];

        foreach ($author->affiliations as $affiliation) {
            $affiliationData = [
                'name' => $affiliation->value,
            ];

            if ($affiliation->ror_id) {
                $affiliationData['affiliationIdentifier'] = $affiliation->ror_id;
                $affiliationData['affiliationIdentifierScheme'] = 'ROR';
                $affiliationData['schemeUri'] = 'https://ror.org';
            }

            $affiliations[] = $affiliationData;
        }

        return !empty($affiliations) ? $affiliations : null;
    }

    /**
     * Build contributors array (excluding authors, including MSL labs)
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildContributors(Resource $resource): ?array
    {
        $contributors = [];

        foreach ($resource->contributors as $contributor) {
            // Check if this is an MSL Laboratory
            if ($contributor->authorable_type === Institution::class) {
                /** @var Institution|null $institution */
                $institution = $contributor->authorable;
                if ($institution instanceof Institution && $institution->isLaboratory()) {
                    // MSL Laboratory - add as HostingInstitution
                    $contributors[] = $this->buildMslLaboratoryContributor($contributor);
                    continue;
                }
            }

            // Regular contributor
            $contributorType = $this->determineContributorType($contributor);
            
            if ($contributor->authorable_type === Person::class) {
                $contributors[] = $this->buildPersonContributor($contributor, $contributorType);
            } elseif ($contributor->authorable_type === Institution::class) {
                $contributors[] = $this->buildInstitutionContributor($contributor, $contributorType);
            }
        }

        return !empty($contributors) ? $contributors : null;
    }

    /**
     * Build MSL Laboratory contributor
     *
     * @param ResourceAuthor $contributor
     * @return array<string, mixed>
     */
    private function buildMslLaboratoryContributor(ResourceAuthor $contributor): array
    {
        /** @var Institution|null $institution */
        $institution = $contributor->authorable;
        
        if (!$institution instanceof Institution) {
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
        if ($institution->identifier) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->identifier,
                    'nameIdentifierScheme' => 'labid',
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
     * Determine contributor type from roles
     *
     * @param ResourceAuthor $contributor
     * @return string
     */
    private function determineContributorType(ResourceAuthor $contributor): string
    {
        // Get the first role or default to 'Other'
        $role = $contributor->roles->first();
        
        if (!$role) {
            return 'Other';
        }

        // Map ERNIE roles to DataCite contributor types
        // Note: Role names in DB have spaces, DataCite uses CamelCase
        $roleMapping = [
            'Contact Person' => 'ContactPerson',
            'Data Collector' => 'DataCollector',
            'Data Curator' => 'DataCurator',
            'Data Manager' => 'DataManager',
            'Distributor' => 'Distributor',
            'Editor' => 'Editor',
            'Hosting Institution' => 'HostingInstitution',
            'Producer' => 'Producer',
            'Project Leader' => 'ProjectLeader',
            'Project Manager' => 'ProjectManager',
            'Project Member' => 'ProjectMember',
            'Registration Agency' => 'RegistrationAgency',
            'Registration Authority' => 'RegistrationAuthority',
            'Related Person' => 'RelatedPerson',
            'Researcher' => 'Researcher',
            'Research Group' => 'ResearchGroup',
            'Rights Holder' => 'RightsHolder',
            'Sponsor' => 'Sponsor',
            'Supervisor' => 'Supervisor',
            'Work Package Leader' => 'WorkPackageLeader',
            'Translator' => 'Translator',
            'Other' => 'Other',
        ];

        return $roleMapping[$role->name] ?? 'Other';
    }

    /**
     * Build a person contributor entry
     *
     * @param ResourceAuthor $contributor
     * @param string $contributorType
     * @return array<string, mixed>
     */
    private function buildPersonContributor(ResourceAuthor $contributor, string $contributorType): array
    {
        /** @var Person|null $person */
        $person = $contributor->authorable;
        
        if (!$person instanceof Person) {
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
        if ($person->last_name && $person->first_name) {
            $contributorData['name'] = "{$person->last_name}, {$person->first_name}";
            $contributorData['givenName'] = $person->first_name;
            $contributorData['familyName'] = $person->last_name;
        } elseif ($person->last_name) {
            $contributorData['name'] = $person->last_name;
            $contributorData['familyName'] = $person->last_name;
        } elseif ($person->first_name) {
            $contributorData['name'] = $person->first_name;
            $contributorData['givenName'] = $person->first_name;
        } else {
            $contributorData['name'] = 'Unknown';
        }

        // Add ORCID if available
        if ($person->orcid) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $person->orcid,
                    'nameIdentifierScheme' => 'ORCID',
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
     * @param ResourceAuthor $contributor
     * @param string $contributorType
     * @return array<string, mixed>
     */
    private function buildInstitutionContributor(ResourceAuthor $contributor, string $contributorType): array
    {
        /** @var Institution|null $institution */
        $institution = $contributor->authorable;
        
        if (!$institution instanceof Institution) {
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

        // Add ROR identifier if available
        if ($institution->identifier_type === 'ROR' && $institution->identifier) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->identifier,
                    'nameIdentifierScheme' => 'ROR',
                    'schemeUri' => 'https://ror.org',
                ],
            ];
        } elseif ($institution->ror_id) {
            $contributorData['nameIdentifiers'] = [
                [
                    'nameIdentifier' => $institution->ror_id,
                    'nameIdentifierScheme' => 'ROR',
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
     * Build subjects array from keywords
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildSubjects(Resource $resource): ?array
    {
        $subjects = [];

        // Add free keywords
        foreach ($resource->keywords as $keyword) {
            $subjects[] = [
                'subject' => $keyword->keyword,
            ];
        }

        // Add controlled keywords (GCMD)
        foreach ($resource->controlledKeywords as $keyword) {
            $subjectData = [
                'subject' => $keyword->text,
                'subjectScheme' => $keyword->scheme,
            ];

            if ($keyword->scheme_uri) {
                $subjectData['schemeUri'] = $keyword->scheme_uri;
            }

            if ($keyword->keyword_id) {
                $subjectData['valueUri'] = $keyword->keyword_id;
            }

            $subjects[] = $subjectData;
        }

        return !empty($subjects) ? $subjects : null;
    }

    /**
     * Build descriptions array
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildDescriptions(Resource $resource): ?array
    {
        $descriptions = [];

        foreach ($resource->descriptions as $description) {
            $descriptionData = [
                'description' => $description->description,
                'descriptionType' => $this->convertDescriptionType($description->description_type),
            ];

            // Add language if available
            if ($resource->language) {
                $descriptionData['lang'] = $resource->language->iso_code ?? 'en';
            }

            $descriptions[] = $descriptionData;
        }

        return !empty($descriptions) ? $descriptions : null;
    }

    /**
     * Convert description type to DataCite format
     *
     * @param string $type
     * @return string
     */
    private function convertDescriptionType(string $type): string
    {
        $mapping = [
            'abstract' => 'Abstract',
            'methods' => 'Methods',
            'series-information' => 'SeriesInformation',
            'table-of-contents' => 'TableOfContents',
            'technical-info' => 'TechnicalInfo',
            'other' => 'Other',
        ];

        return $mapping[$type] ?? 'Other';
    }

    /**
     * Build dates array
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildDates(Resource $resource): ?array
    {
        $dates = [];

        foreach ($resource->dates as $date) {
            $dateData = [
                'dateType' => $this->convertDateType($date->date_type),
            ];

            // Build date string
            if ($date->start_date && $date->end_date) {
                $dateData['date'] = "{$date->start_date}/{$date->end_date}";
            } elseif ($date->start_date) {
                $dateData['date'] = $date->start_date;
            } elseif ($date->end_date) {
                $dateData['date'] = $date->end_date;
            } else {
                continue; // Skip if no date
            }

            // Add date information if available
            if ($date->date_information) {
                $dateData['dateInformation'] = $date->date_information;
            }

            $dates[] = $dateData;
        }

        return !empty($dates) ? $dates : null;
    }

    /**
     * Convert date type to DataCite format
     *
     * @param string $type
     * @return string
     */
    private function convertDateType(string $type): string
    {
        $mapping = [
            'accepted' => 'Accepted',
            'available' => 'Available',
            'copyrighted' => 'Copyrighted',
            'collected' => 'Collected',
            'created' => 'Created',
            'issued' => 'Issued',
            'submitted' => 'Submitted',
            'updated' => 'Updated',
            'valid' => 'Valid',
            'withdrawn' => 'Withdrawn',
            'coverage' => 'Coverage', // DataCite 4.6 addition
            'other' => 'Other',
        ];

        return $mapping[$type] ?? 'Other';
    }

    /**
     * Build rights list from licenses
     *
     * @param Resource $resource
     * @return array<int, array<string, string>>|null
     */
    private function buildRightsList(Resource $resource): ?array
    {
        $rightsList = [];

        foreach ($resource->licenses as $license) {
            $rightsData = [
                'rights' => $license->name,
            ];

            // Note: License model doesn't have url or spdx_identifier fields
            // If needed in the future, add these fields to the licenses table

            // Add language if available
            if ($resource->language) {
                $rightsData['lang'] = $resource->language->iso_code ?? 'en';
            }

            $rightsList[] = $rightsData;
        }

        return !empty($rightsList) ? $rightsList : null;
    }

    /**
     * Build geo locations from spatial/temporal coverages
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildGeoLocations(Resource $resource): ?array
    {
        $geoLocations = [];

        foreach ($resource->coverages as $coverage) {
            $geoLocation = [];

            // Add bounding box if spatial data exists
            if ($coverage->lat_min !== null || $coverage->lat_max !== null ||
                $coverage->lon_min !== null || $coverage->lon_max !== null) {
                $geoLocation['geoLocationBox'] = [
                    'westBoundLongitude' => $coverage->lon_min,
                    'eastBoundLongitude' => $coverage->lon_max,
                    'southBoundLatitude' => $coverage->lat_min,
                    'northBoundLatitude' => $coverage->lat_max,
                ];
            }

            // Add description/place
            if ($coverage->description) {
                $geoLocation['geoLocationPlace'] = $coverage->description;
            }

            if (!empty($geoLocation)) {
                $geoLocations[] = $geoLocation;
            }
        }

        return !empty($geoLocations) ? $geoLocations : null;
    }

    /**
     * Build related identifiers array
     *
     * @param Resource $resource
     * @return array<int, array<string, mixed>>|null
     */
    private function buildRelatedIdentifiers(Resource $resource): ?array
    {
        $relatedIdentifiers = [];

        foreach ($resource->relatedIdentifiers as $relatedIdentifier) {
            $relatedData = [
                'relatedIdentifier' => $relatedIdentifier->identifier,
                'relatedIdentifierType' => $relatedIdentifier->identifier_type,
                'relationType' => $relatedIdentifier->relation_type,
            ];

            // Add resource type general if available in metadata
            if (isset($relatedIdentifier->related_metadata['resourceTypeGeneral'])) {
                $relatedData['resourceTypeGeneral'] = $relatedIdentifier->related_metadata['resourceTypeGeneral'];
            }

            $relatedIdentifiers[] = $relatedData;
        }

        return !empty($relatedIdentifiers) ? $relatedIdentifiers : null;
    }

    /**
     * Build funding references array
     *
     * @param Resource $resource
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
                $fundingData['funderIdentifierType'] = $funding->funder_identifier_type ?? 'Other';
            }

            if ($funding->award_number) {
                $fundingData['awardNumber'] = $funding->award_number;
            }

            if ($funding->award_title) {
                $fundingData['awardTitle'] = $funding->award_title;
            }

            if ($funding->award_uri) {
                $fundingData['awardUri'] = $funding->award_uri;
            }

            $fundingReferences[] = $fundingData;
        }

        return !empty($fundingReferences) ? $fundingReferences : null;
    }
}
