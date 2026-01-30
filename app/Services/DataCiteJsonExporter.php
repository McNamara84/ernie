<?php

namespace App\Services;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Traits\DataCiteExporterHelpers;

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
    use DataCiteExporterHelpers;

    /**
     * Export a Resource to DataCite JSON format
     *
     * @param  resource  $resource  The resource to export
     * @return array<string, mixed> The DataCite JSON structure
     */
    public function export(Resource $resource): array
    {
        // Load all necessary relationships using shared method
        $resource->load($this->getRequiredRelations());

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
            'titles' => $this->buildTitles($resource),
            'publisher' => $this->buildPublisher($resource),
            'publicationYear' => (string) $resource->publication_year,
            'types' => $this->buildTypes($resource),
            'creators' => $this->buildCreators($resource),
            'schemaVersion' => 'http://datacite.org/schema/kernel-4',
        ];

        // Add identifiers only if DOI is present (required for registration, optional for export)
        $identifiers = $this->buildIdentifiers($resource);
        if (! empty($identifiers)) {
            $attributes['identifiers'] = $identifiers;
        }

        // Add doi only if it has a value
        if ($resource->doi !== null) {
            $attributes['doi'] = $resource->doi;
        }

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

        // Language: use resource language if set, or default to 'en' for IGSN resources
        if ($resource->language) {
            $attributes['language'] = $resource->language->code ?? 'en';
        } elseif ($resource->igsnMetadata) {
            // IGSN resources default to English since IGSN CSV doesn't include language
            $attributes['language'] = 'en';
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

        // For IGSN resources, export AlternativeTitles as alternateIdentifiers
        if ($alternateIdentifiers = $this->buildAlternateIdentifiers($resource)) {
            $attributes['alternateIdentifiers'] = $alternateIdentifiers;
        }

        if ($relatedIdentifiers = $this->buildRelatedIdentifiers($resource)) {
            $attributes['relatedIdentifiers'] = $relatedIdentifiers;
        }

        if ($sizes = $this->buildSizes($resource)) {
            $attributes['sizes'] = $sizes;
        }

        if ($fundingReferences = $this->buildFundingReferences($resource)) {
            $attributes['fundingReferences'] = $fundingReferences;
        }

        return $attributes;
    }

    /**
     * Build the identifiers array.
     *
     * Returns an empty array if DOI is not set, allowing export of draft resources.
     * The identifiers field is only required for DataCite registration, not for preview exports.
     *
     * @return array<int, array<string, string>>
     */
    private function buildIdentifiers(Resource $resource): array
    {
        if (empty($resource->doi)) {
            return [];
        }

        return [
            [
                'identifier' => $resource->doi,
                'identifierType' => 'DOI',
            ],
        ];
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

            // Map title types to DataCite format - skip for MainTitle (no titleType attribute in XML)
            if (! $title->isMainTitle()) {
                // Convert slug format to DataCite TitleCase format
                // Use null-safe operator for legacy data where titleType may be null
                /** @phpstan-ignore nullsafe.neverNull (titleType may be null in legacy data before migration) */
                $slug = $title->titleType?->slug ?? 'Other';
                $titleData['titleType'] = $this->convertTitleType($slug);
            }

            // Add language if available
            if ($resource->language) {
                $titleData['lang'] = $resource->language->code ?? 'en';
            } elseif ($resource->igsnMetadata) {
                // IGSN resources default to English since IGSN CSV doesn't include language
                $titleData['lang'] = 'en';
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
     * Build publisher information according to DataCite Schema 4.6.
     *
     * Uses the resource's publisher if available, otherwise falls back
     * to the default publisher (GFZ Data Services).
     *
     * @return array<string, string|null>
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/properties/publisher/
     */
    private function buildPublisher(Resource $resource): array
    {
        $publisher = $resource->publisher ?? Publisher::getDefault();

        if (! $publisher) {
            // Ultimate fallback if no default publisher exists in database
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

        // Add language attribute (DataCite 4.6)
        if ($publisher->language) {
            $data['lang'] = $publisher->language;
        }

        return $data;
    }

    /**
     * Mapping from database resource type names to DataCite resourceTypeGeneral values.
     * DataCite requires PascalCase without spaces.
     *
     * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/appendices/appendix-1/resourceTypeGeneral/
     *
     * @var array<string, string>
     */
    private const RESOURCE_TYPE_GENERAL_MAP = [
        'Audiovisual' => 'Audiovisual',
        'Award' => 'Award',
        'Book' => 'Book',
        'Book Chapter' => 'BookChapter',
        'Collection' => 'Collection',
        'Computational Notebook' => 'ComputationalNotebook',
        'Conference Paper' => 'ConferencePaper',
        'Conference Proceeding' => 'ConferenceProceeding',
        'Data Paper' => 'DataPaper',
        'Dataset' => 'Dataset',
        'Dissertation' => 'Dissertation',
        'Event' => 'Event',
        'Image' => 'Image',
        'Interactive Resource' => 'InteractiveResource',
        'Instrument' => 'Instrument',
        'Journal' => 'Journal',
        'Journal Article' => 'JournalArticle',
        'Model' => 'Model',
        'Output Management Plan' => 'OutputManagementPlan',
        'Peer Review' => 'PeerReview',
        'Physical Object' => 'PhysicalObject',
        'Preprint' => 'Preprint',
        'Project' => 'Project',
        'Report' => 'Report',
        'Service' => 'Service',
        'Software' => 'Software',
        'Sound' => 'Sound',
        'Standard' => 'Standard',
        'Study Registration' => 'StudyRegistration',
        'Text' => 'Text',
        'Workflow' => 'Workflow',
        'Other' => 'Other',
    ];

    /**
     * Build types (resource type) information.
     *
     * For IGSN resources (PhysicalObject), uses sample_type and/or material
     * from IGSN metadata as the specific resourceType value.
     *
     * @return array<string, string>
     */
    private function buildTypes(Resource $resource): array
    {
        $resourceType = $resource->resourceType;
        $typeName = $resourceType->name ?? 'Other';

        // Use explicit mapping for DataCite format
        // Fallback to removing spaces for any unmapped types
        $dataCiteType = self::RESOURCE_TYPE_GENERAL_MAP[$typeName]
            ?? str_replace(' ', '', $typeName);

        // For PhysicalObject (IGSN), build specific resourceType from sample_type and material
        $specificType = $typeName;
        if ($dataCiteType === 'PhysicalObject' && $resource->igsnMetadata) {
            $specificType = $this->buildIgsnResourceType($resource->igsnMetadata);
        }

        return [
            'resourceTypeGeneral' => $dataCiteType,
            'resourceType' => $specificType,
        ];
    }

    /**
     * Build specific resourceType value for IGSN from sample_type and material.
     *
     * Combines sample_type and material with a colon separator when both are available.
     * Returns "Physical Object" as fallback when neither is set.
     */
    private function buildIgsnResourceType(\App\Models\IgsnMetadata $igsnMetadata): string
    {
        $parts = array_filter([
            $igsnMetadata->sample_type,
            $igsnMetadata->material,
        ]);

        if (empty($parts)) {
            return 'Physical Object';
        }

        return implode(': ', $parts);
    }

    /**
     * Build creators array from authors.
     *
     * For IGSN resources, contributors are also included as creators to ensure
     * all contributing persons are prominently represented in the metadata.
     * Duplicates are avoided by checking both ORCID and normalized name.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildCreators(Resource $resource): array
    {
        $creators = [];
        $seenIdentifiers = []; // Associative set for O(1) duplicate lookups

        // 1. Build original creators
        foreach ($resource->creators as $creator) {
            if ($creator->creatorable_type === Person::class) {
                $identifiers = $this->getPersonIdentifiers($creator);
                // Skip if person cannot be resolved
                if ($identifiers === null) {
                    continue;
                }
                $creators[] = $this->buildPersonCreator($creator);
                // Mark all identifiers as seen (both ORCID and name if available)
                foreach ($identifiers as $identifier) {
                    $seenIdentifiers[$identifier] = true;
                }
            } elseif ($creator->creatorable_type === Institution::class) {
                $creators[] = $this->buildInstitutionCreator($creator);
            }
        }

        // 2. For IGSN resources: Add person contributors as creators (avoiding duplicates)
        if ($resource->igsnMetadata) {
            foreach ($resource->contributors as $contributor) {
                // Only process persons, skip institutions
                if ($contributor->contributorable_type !== Person::class) {
                    continue;
                }

                $identifiers = $this->getPersonIdentifiers($contributor);
                // Skip if person cannot be resolved
                if ($identifiers === null) {
                    continue;
                }

                // Check for duplicates - if any identifier matches, it's a duplicate
                $isDuplicate = false;
                foreach ($identifiers as $identifier) {
                    if (isset($seenIdentifiers[$identifier])) {
                        $isDuplicate = true;
                        break;
                    }
                }

                if ($isDuplicate) {
                    continue;
                }

                $creators[] = $this->buildPersonAsCreatorFromContributor($contributor);
                // Mark all identifiers as seen
                foreach ($identifiers as $identifier) {
                    $seenIdentifiers[$identifier] = true;
                }
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
     * Get unique identifiers for a person to detect duplicates.
     *
     * Returns both ORCID (if available) and normalized name to ensure
     * duplicates are detected even when one record has ORCID and another doesn't.
     *
     * @return array<int, string>|null Array of identifiers, or null if person cannot be resolved
     */
    private function getPersonIdentifiers(ResourceCreator|ResourceContributor $author): ?array
    {
        /** @var Person|null $person */
        $person = $author instanceof ResourceCreator
            ? $author->creatorable
            : $author->contributorable;

        if (! $person instanceof Person) {
            return null;
        }

        $identifiers = [];

        // Always include normalized name for matching
        $name = strtolower(trim(($person->family_name ?? '') . ',' . ($person->given_name ?? '')));
        $identifiers[] = 'name:' . $name;

        // Also include ORCID if available
        // Consistent with buildPersonNameIdentifier(): null scheme defaults to ORCID
        if (! empty($person->name_identifier)) {
            $scheme = $person->name_identifier_scheme ?? 'ORCID';
            if (strtoupper($scheme) === 'ORCID') {
                $identifiers[] = 'orcid:' . $person->name_identifier;
            }
        }

        return $identifiers;
    }

    /**
     * Build a creator entry from a person contributor (for IGSN export).
     *
     * @return array<string, mixed>
     */
    private function buildPersonAsCreatorFromContributor(ResourceContributor $contributor): array
    {
        /** @var Person|null $person */
        $person = $contributor->contributorable;

        if (! $person instanceof Person) {
            return [
                'name' => 'Unknown',
                'nameType' => 'Personal',
            ];
        }

        // Reuse the trait method for consistent creator data building
        return $this->buildPersonCreatorData($contributor, $person);
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

        return $this->buildPersonCreatorData($creator, $person);
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

        return $this->buildInstitutionCreatorData($creator, $institution);
    }

    /**
     * Build affiliations for a person or institution (delegates to trait)
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function buildAffiliations(ResourceCreator|ResourceContributor $author): ?array
    {
        $affiliations = $this->transformAffiliations($author);

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

            // Regular contributor - use contributorType slug for DataCite compliance
            // DataCite expects PascalCase values like "ProjectLeader" not "Project Leader"
            $contributorType = $contributor->contributorType->slug ?? 'Other';

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

        $data = [
            'name' => $this->formatPersonName($person),
            'nameType' => 'Personal',
            'contributorType' => $contributorType,
        ];

        // Add given/family name separately
        if ($person->given_name) {
            $data['givenName'] = $person->given_name;
        }
        if ($person->family_name) {
            $data['familyName'] = $person->family_name;
        }

        // Add name identifier (ORCID)
        if ($nameIdentifier = $this->buildPersonNameIdentifier($person)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($contributor)) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
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

        $data = [
            'name' => $this->formatInstitutionName($institution),
            'nameType' => 'Organizational',
            'contributorType' => $contributorType,
        ];

        // Add name identifier (ROR)
        if ($nameIdentifier = $this->buildInstitutionNameIdentifier($institution)) {
            $data['nameIdentifiers'] = [$nameIdentifier];
        }

        // Add affiliations
        if ($affiliations = $this->buildAffiliations($contributor)) {
            $data['affiliation'] = $affiliations;
        }

        return $data;
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
                $descriptionData['lang'] = $resource->language->code ?? 'en';
            } elseif ($resource->igsnMetadata) {
                // IGSN resources default to English since IGSN CSV doesn't include language
                $descriptionData['lang'] = 'en';
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

            // Format date value using shared trait method
            $dateValue = $this->formatDateValue($date);

            // Skip dates where no value could be determined
            if ($dateValue === null) {
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
                $rightsData['lang'] = $resource->language->code ?? 'en';
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
            if ($point = $this->transformGeoLocationPoint($geoLocation)) {
                $geoLocationData['geoLocationPoint'] = $point;
            }

            // Add bounding box if available
            if ($box = $this->transformGeoLocationBox($geoLocation)) {
                $geoLocationData['geoLocationBox'] = $box;
            }

            // Add polygons if available
            if ($polygon = $this->transformGeoLocationPolygon($geoLocation)) {
                $geoLocationData['geoLocationPolygon'] = $polygon;
            }

            if (! empty($geoLocationData)) {
                $geoLocationsData[] = $geoLocationData;
            }
        }

        return ! empty($geoLocationsData) ? $geoLocationsData : null;
    }

    /**
     * Build alternate identifiers array.
     *
     * Exports AlternateIdentifier records associated with the resource.
     * For IGSN resources, this includes:
     * - 'name' field with type "Local accession number"
     * - 'sample_other_names' field with type "Local sample name"
     *
     * @return array<int, array{alternateIdentifier: string, alternateIdentifierType: string}>|null
     *
     * @see https://github.com/McNamara84/ernie/issues/465
     */
    private function buildAlternateIdentifiers(Resource $resource): ?array
    {
        if ($resource->alternateIdentifiers->isEmpty()) {
            return null;
        }

        $alternateIdentifiers = [];

        foreach ($resource->alternateIdentifiers as $altId) {
            $alternateIdentifiers[] = [
                'alternateIdentifier' => $altId->value,
                'alternateIdentifierType' => $altId->type,
            ];
        }

        return $alternateIdentifiers;
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
                // DataCite expects PascalCase values like "IsCitedBy" not "Is Cited By"
                'relationType' => $relatedIdentifier->relationType->slug ?? 'References',
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
            $fundingReferences[] = $this->transformFundingReference($funding);
        }

        return ! empty($fundingReferences) ? $fundingReferences : null;
    }

    /**
     * Build sizes array
     *
     * DataCite sizes is a simple array of strings.
     *
     * @return list<string>|null
     */
    private function buildSizes(Resource $resource): ?array
    {
        if ($resource->sizes->isEmpty()) {
            return null;
        }

        $sizes = [];

        foreach ($resource->sizes as $size) {
            $sizes[] = $size->value;
        }

        return $sizes;
    }
}
