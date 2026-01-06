<?php

namespace App\Services;

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Format;
use App\Models\FunderIdentifierType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Size;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transforms DataCite API JSON responses into ERNIE Resource models.
 *
 * Maps all DataCite metadata fields to the local database schema.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.6/
 */
class DataCiteToResourceTransformer
{
    /**
     * Cached lookup tables for performance.
     *
     * @var array<string, array<string, int>>
     */
    private array $lookupCache = [];

    /**
     * Transform a DataCite DOI record into a Resource model.
     *
     * @param  array<string, mixed>  $doiData  The DOI record from DataCite API
     * @param  int  $userId  The user ID to set as created_by
     * @return Resource The created Resource model
     *
     * @throws \Exception If transformation fails
     */
    public function transform(array $doiData, int $userId): Resource
    {
        $attributes = $doiData['attributes'] ?? $doiData;

        return DB::transaction(function () use ($attributes, $userId) {
            // Create the main Resource
            $resource = $this->createResource($attributes, $userId);

            // Transform all relations
            $this->transformTitles($attributes['titles'] ?? [], $resource);
            $this->transformCreators($attributes['creators'] ?? [], $resource);
            $this->transformContributors($attributes['contributors'] ?? [], $resource);
            $this->transformDescriptions($attributes['descriptions'] ?? [], $resource);
            $this->transformSubjects($attributes['subjects'] ?? [], $resource);
            $this->transformDates($attributes['dates'] ?? [], $resource);
            $this->transformGeoLocations($attributes['geoLocations'] ?? [], $resource);
            $this->transformRelatedIdentifiers($attributes['relatedIdentifiers'] ?? [], $resource);
            $this->transformFundingReferences($attributes['fundingReferences'] ?? [], $resource);
            $this->transformRights($attributes['rightsList'] ?? [], $resource);
            $this->transformSizes($attributes['sizes'] ?? [], $resource);
            $this->transformFormats($attributes['formats'] ?? [], $resource);

            return $resource;
        });
    }

    /**
     * Create the main Resource model.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createResource(array $attributes, int $userId): Resource
    {
        $resourceTypeId = $this->resolveResourceType($attributes['types'] ?? []);
        $languageId = $this->resolveLanguage($attributes['language'] ?? null);
        $publisherId = $this->resolvePublisher($attributes['publisher'] ?? null);

        return Resource::create([
            'doi' => $attributes['doi'] ?? null,
            'publication_year' => $attributes['publicationYear'] ?? null,
            'version' => $attributes['version'] ?? null,
            'resource_type_id' => $resourceTypeId,
            'language_id' => $languageId,
            'publisher_id' => $publisherId,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
    }

    /**
     * Resolve resource type from DataCite types object.
     *
     * @param  array<string, mixed>  $types
     */
    private function resolveResourceType(array $types): ?int
    {
        $typeGeneral = $types['resourceTypeGeneral'] ?? null;

        if ($typeGeneral === null) {
            // Default to 'Other' if no type is provided
            return $this->getLookupId(ResourceType::class, 'slug', 'other');
        }

        // Convert PascalCase to kebab-case for slug matching
        // e.g., "Dataset" -> "dataset", "JournalArticle" -> "journal-article", "XMLSchema" -> "xml-schema"
        $slug = strtolower((string) preg_replace('/(?<!^)(?=[A-Z][a-z])|(?<=[a-z])(?=[A-Z])/', '-', $typeGeneral));

        $typeId = $this->getLookupId(ResourceType::class, 'slug', $slug);

        // Fall back to 'Other' if type is not found
        return $typeId ?? $this->getLookupId(ResourceType::class, 'slug', 'other');
    }

    /**
     * Resolve language ID from ISO 639-1 code.
     */
    private function resolveLanguage(?string $languageCode): ?int
    {
        if ($languageCode === null) {
            return null;
        }

        return $this->getLookupId(Language::class, 'code', $languageCode);
    }

    /**
     * Resolve or create publisher.
     *
     * @param  string|array<string, mixed>|null  $publisher
     */
    private function resolvePublisher(string|array|null $publisher): ?int
    {
        // For imported datasets, use DataCite publisher if provided
        // If not provided, fall back to the default publisher (e.g., 'GFZ Data Services')
        if ($publisher === null) {
            $defaultPublisher = Publisher::getDefault();
            return $defaultPublisher?->id;
        }

        // DataCite 4.5+ returns publisher as object
        $publisherName = is_array($publisher) ? ($publisher['name'] ?? null) : $publisher;

        if ($publisherName === null) {
            // Fall back to default publisher if name couldn't be extracted
            $defaultPublisher = Publisher::getDefault();
            return $defaultPublisher?->id;
        }

        $existing = Publisher::where('name', $publisherName)->first();

        if ($existing) {
            return $existing->id;
        }

        // Create new publisher
        $newPublisher = Publisher::create([
            'name' => $publisherName,
            'identifier' => is_array($publisher) ? ($publisher['publisherIdentifier'] ?? null) : null,
            'identifier_scheme' => is_array($publisher) ? ($publisher['publisherIdentifierScheme'] ?? null) : null,
            'scheme_uri' => is_array($publisher) ? ($publisher['schemeUri'] ?? null) : null,
            'language' => 'en',
            'is_default' => false,
        ]);

        return $newPublisher->id;
    }

    /**
     * Transform titles from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $titles
     */
    private function transformTitles(array $titles, Resource $resource): void
    {
        foreach ($titles as $titleData) {
            $titleValue = $titleData['title'] ?? null;

            if ($titleValue === null) {
                continue;
            }

            // Get title type - default to MainTitle if not specified
            $titleType = $titleData['titleType'] ?? 'MainTitle';
            $titleTypeId = $this->getLookupId(TitleType::class, 'slug', $titleType);

            // Fall back to MainTitle or Other if not found
            if ($titleTypeId === null) {
                $titleTypeId = $this->getLookupId(TitleType::class, 'slug', 'MainTitle')
                    ?? $this->getLookupId(TitleType::class, 'slug', 'Other');
            }

            Title::create([
                'resource_id' => $resource->id,
                'value' => $titleValue,
                'title_type_id' => $titleTypeId,
                'language' => $titleData['lang'] ?? null,
            ]);
        }
    }

    /**
     * Transform creators from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $creators
     */
    private function transformCreators(array $creators, Resource $resource): void
    {
        foreach ($creators as $position => $creatorData) {
            $nameType = $creatorData['nameType'] ?? 'Personal';

            if ($nameType === 'Organizational') {
                $entity = $this->findOrCreateInstitution($creatorData);
                $entityType = Institution::class;
            } else {
                $entity = $this->findOrCreatePerson($creatorData);
                $entityType = Person::class;
            }

            $resourceCreator = ResourceCreator::create([
                'resource_id' => $resource->id,
                'creatorable_type' => $entityType,
                'creatorable_id' => $entity->id,
                'position' => $position + 1,
                'is_contact' => false,
            ]);

            // Add affiliations
            $this->transformAffiliations($creatorData['affiliation'] ?? [], $resourceCreator);
        }
    }

    /**
     * Transform contributors from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $contributors
     */
    private function transformContributors(array $contributors, Resource $resource): void
    {
        foreach ($contributors as $position => $contributorData) {
            $nameType = $contributorData['nameType'] ?? 'Personal';

            if ($nameType === 'Organizational') {
                $entity = $this->findOrCreateInstitution($contributorData);
                $entityType = Institution::class;
            } else {
                $entity = $this->findOrCreatePerson($contributorData);
                $entityType = Person::class;
            }

            $contributorTypeId = null;
            if (isset($contributorData['contributorType'])) {
                $contributorTypeId = $this->getLookupId(
                    ContributorType::class,
                    'slug',
                    $contributorData['contributorType']
                );
            }

            // Fallback to 'Other' if contributor type not found
            if ($contributorTypeId === null) {
                $contributorTypeId = $this->getLookupId(
                    ContributorType::class,
                    'slug',
                    'Other'
                );

                // If still null, query directly (shouldn't happen but safety net)
                if ($contributorTypeId === null) {
                    $contributorTypeId = ContributorType::where('slug', 'Other')->value('id');
                }
            }

            // Skip this contributor if we still can't find a valid type
            if ($contributorTypeId === null) {
                Log::warning('Skipping contributor without valid type', [
                    'resource_id' => $resource->id,
                    'contributor_type' => $contributorData['contributorType'] ?? 'null',
                ]);

                continue;
            }

            $resourceContributor = ResourceContributor::create([
                'resource_id' => $resource->id,
                'contributorable_type' => $entityType,
                'contributorable_id' => $entity->id,
                'contributor_type_id' => $contributorTypeId,
                'position' => $position + 1,
            ]);

            // Add affiliations
            $this->transformAffiliations($contributorData['affiliation'] ?? [], $resourceContributor);
        }
    }

    /**
     * Transform affiliations for a creator or contributor.
     *
     * @param  array<int, array<string, mixed>|string>  $affiliations
     */
    private function transformAffiliations(array $affiliations, ResourceCreator|ResourceContributor $parent): void
    {
        foreach ($affiliations as $affiliationData) {
            // Handle both old string format and new object format
            $name = is_string($affiliationData)
                ? $affiliationData
                : ($affiliationData['name'] ?? null);

            if ($name === null) {
                Log::warning('Skipping affiliation with missing name', [
                    'parent_type' => $parent::class,
                    'parent_id' => $parent->id,
                    'affiliation_data' => is_array($affiliationData) ? $affiliationData : 'string without name',
                ]);
                continue;
            }

            $identifier = null;
            $scheme = null;
            $schemeUri = null;

            if (is_array($affiliationData)) {
                $identifier = $affiliationData['affiliationIdentifier'] ?? null;
                $scheme = $affiliationData['affiliationIdentifierScheme'] ?? null;
                $schemeUri = $affiliationData['schemeUri'] ?? null;
            }

            Affiliation::create([
                'affiliatable_type' => $parent::class,
                'affiliatable_id' => $parent->id,
                'name' => $name,
                'identifier' => $identifier,
                'identifier_scheme' => $scheme,
                'scheme_uri' => $schemeUri,
            ]);
        }
    }

    /**
     * Find or create a Person entity.
     *
     * @param  array<string, mixed>  $data
     */
    private function findOrCreatePerson(array $data): Person
    {
        $familyName = $data['familyName'] ?? null;
        $givenName = $data['givenName'] ?? null;

        // If no structured name, try to parse from 'name' field
        if ($familyName === null && isset($data['name'])) {
            $parts = $this->parsePersonName($data['name']);
            $familyName = $parts['family'];
            $givenName = $parts['given'];
        }

        // Extract ORCID from name identifiers
        $orcid = null;
        $scheme = null;
        $schemeUri = null;

        foreach ($data['nameIdentifiers'] ?? [] as $nameId) {
            if (($nameId['nameIdentifierScheme'] ?? '') === 'ORCID') {
                $orcid = $nameId['nameIdentifier'] ?? null;
                $scheme = 'ORCID';
                $schemeUri = $nameId['schemeUri'] ?? 'https://orcid.org';
                break;
            }
        }

        // Try to find existing person by ORCID first
        if ($orcid !== null) {
            $existing = Person::where('name_identifier', $orcid)
                ->where('name_identifier_scheme', 'ORCID')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Try to find by name with strict matching
        // Note: We intentionally use strict matching (family_name + given_name) to avoid
        // incorrectly merging different people with the same family name. For example,
        // "John Smith" and "Jane Smith" should not be merged. If only family_name matches
        // but given_name differs, we create a new Person record. This is the safer approach
        // for research data where author identity matters.
        if ($familyName !== null) {
            $query = Person::where('family_name', $familyName);

            // Handle given_name matching - must explicitly handle null cases
            // to avoid creating duplicates when one side has null given_name
            if ($givenName !== null) {
                $query->where('given_name', $givenName);
            } else {
                // If incoming givenName is null, only match persons with null given_name
                // This prevents matching "Smith" (null given_name) with "John Smith"
                $query->whereNull('given_name');
            }

            $existing = $query->first();

            if ($existing) {
                // Update ORCID if we have one and existing doesn't
                if ($orcid !== null && $existing->name_identifier === null) {
                    $existing->update([
                        'name_identifier' => $orcid,
                        'name_identifier_scheme' => $scheme,
                        'scheme_uri' => $schemeUri,
                    ]);
                }

                return $existing;
            }
        }

        // Create new person
        return Person::create([
            'given_name' => $givenName,
            'family_name' => $familyName,
            'name_identifier' => $orcid,
            'name_identifier_scheme' => $scheme,
            'scheme_uri' => $schemeUri,
        ]);
    }

    /**
     * Find or create an Institution entity.
     *
     * @param  array<string, mixed>  $data
     */
    private function findOrCreateInstitution(array $data): Institution
    {
        $name = $data['name'] ?? 'Unknown Institution';

        // Extract ROR from name identifiers
        $ror = null;
        $scheme = null;
        $schemeUri = null;

        foreach ($data['nameIdentifiers'] ?? [] as $nameId) {
            if (($nameId['nameIdentifierScheme'] ?? '') === 'ROR') {
                $ror = $nameId['nameIdentifier'] ?? null;
                $scheme = 'ROR';
                $schemeUri = $nameId['schemeUri'] ?? 'https://ror.org';
                break;
            }
        }

        // Try to find existing institution by ROR first
        if ($ror !== null) {
            $existing = Institution::where('name_identifier', $ror)
                ->where('name_identifier_scheme', 'ROR')
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        // Try to find by name
        $existing = Institution::where('name', $name)->first();

        if ($existing) {
            // Update ROR if we have one and existing doesn't
            if ($ror !== null && $existing->name_identifier === null) {
                $existing->update([
                    'name_identifier' => $ror,
                    'name_identifier_scheme' => $scheme,
                    'scheme_uri' => $schemeUri,
                ]);
            }

            return $existing;
        }

        // Create new institution
        return Institution::create([
            'name' => $name,
            'name_identifier' => $ror,
            'name_identifier_scheme' => $scheme,
            'scheme_uri' => $schemeUri,
        ]);
    }

    /**
     * Transform descriptions from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $descriptions
     */
    private function transformDescriptions(array $descriptions, Resource $resource): void
    {
        foreach ($descriptions as $descriptionData) {
            $description = $descriptionData['description'] ?? null;

            // Skip if description is null or empty
            if ($description === null || trim((string) $description) === '') {
                continue;
            }

            $descriptionTypeId = null;
            if (isset($descriptionData['descriptionType'])) {
                $descriptionTypeId = $this->getLookupId(
                    DescriptionType::class,
                    'slug',
                    $descriptionData['descriptionType']
                );
            }

            // Skip if we couldn't resolve the description type (it's required)
            if ($descriptionTypeId === null) {
                Log::warning('Skipping description without valid type', [
                    'resource_id' => $resource->id,
                    'description_type' => $descriptionData['descriptionType'] ?? 'null',
                ]);

                continue;
            }

            Description::create([
                'resource_id' => $resource->id,
                'value' => $description,
                'description_type_id' => $descriptionTypeId,
                'language' => $descriptionData['lang'] ?? null,
            ]);
        }
    }

    /**
     * Transform subjects from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $subjects
     */
    private function transformSubjects(array $subjects, Resource $resource): void
    {
        foreach ($subjects as $subjectData) {
            $value = $subjectData['subject'] ?? null;

            if ($value === null) {
                continue;
            }

            Subject::create([
                'resource_id' => $resource->id,
                'value' => $value,
                'language' => $subjectData['lang'] ?? 'en',
                'subject_scheme' => $subjectData['subjectScheme'] ?? null,
                'scheme_uri' => $subjectData['schemeUri'] ?? null,
                'value_uri' => $subjectData['valueUri'] ?? null,
                'classification_code' => $subjectData['classificationCode'] ?? null,
            ]);
        }
    }

    /**
     * Transform dates from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $dates
     */
    private function transformDates(array $dates, Resource $resource): void
    {
        foreach ($dates as $dateData) {
            $date = $dateData['date'] ?? null;

            if ($date === null) {
                continue;
            }

            $dateTypeId = null;
            if (isset($dateData['dateType'])) {
                $dateTypeId = $this->getLookupId(DateType::class, 'slug', $dateData['dateType']);
            }

            // Skip if we couldn't resolve the date type (it's required)
            if ($dateTypeId === null) {
                continue;
            }

            // Parse the date - DataCite uses RKMS-ISO8601 which can be a range with /
            $dateValue = null;
            $startDate = null;
            $endDate = null;

            if (str_contains($date, '/')) {
                // Date range format: YYYY-MM-DD/YYYY-MM-DD or open-ended "YYYY-MM-DD/"
                // RKMS-ISO8601 allows open-ended ranges where end date is omitted.
                //
                // Parsing logic for "2020-01-01/":
                //   explode('/', '2020-01-01/', 2) => ['2020-01-01', '']
                //   parts[1] is empty string, so endDate becomes null
                //   Result: startDate='2020-01-01', endDate=null (stored as open-ended range)
                //
                // Malformed input handling:
                // - "2020-01-01//" -> explode limit=2 yields ['2020-01-01', '/'], parseDate('/') returns null
                // - "//" -> explode limit=2 yields ['', '/'], both parsed as null
                // - Empty or only slashes result in null dates, which are handled gracefully below.
                //
                // Note: isRange() returns true only for CLOSED ranges (both dates present).
                // isOpenEndedRange() returns true when start_date is set but end_date is null.
                // During export, open-ended ranges are exported as single dates because
                // DataCite's schema doesn't support the trailing slash format.
                $parts = explode('/', $date, 2);
                $startDate = $this->parseDate($parts[0], false);
                // Empty string after trailing slash results in null endDate (intentional)
                // isEndDate=true ensures year-only formats like "2020" become "2020-12-31"
                $endDate = ! empty($parts[1]) ? $this->parseDate($parts[1], true) : null;
            } else {
                $dateValue = $this->parseDate($date);
            }

            ResourceDate::create([
                'resource_id' => $resource->id,
                'date_value' => $dateValue,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'date_type_id' => $dateTypeId,
                'date_information' => $dateData['dateInformation'] ?? null,
            ]);
        }
    }

    /**
     * Transform geo locations from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $geoLocations
     */
    private function transformGeoLocations(array $geoLocations, Resource $resource): void
    {
        foreach ($geoLocations as $geoData) {
            $point = $geoData['geoLocationPoint'] ?? null;
            $box = $geoData['geoLocationBox'] ?? null;

            GeoLocation::create([
                'resource_id' => $resource->id,
                'place' => $geoData['geoLocationPlace'] ?? null,
                'point_longitude' => $point['pointLongitude'] ?? null,
                'point_latitude' => $point['pointLatitude'] ?? null,
                'west_bound_longitude' => $box['westBoundLongitude'] ?? null,
                'east_bound_longitude' => $box['eastBoundLongitude'] ?? null,
                'south_bound_latitude' => $box['southBoundLatitude'] ?? null,
                'north_bound_latitude' => $box['northBoundLatitude'] ?? null,
            ]);
        }
    }

    /**
     * Transform related identifiers from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $relatedIdentifiers
     */
    private function transformRelatedIdentifiers(array $relatedIdentifiers, Resource $resource): void
    {
        foreach ($relatedIdentifiers as $position => $relIdData) {
            $identifier = $relIdData['relatedIdentifier'] ?? null;

            if ($identifier === null) {
                continue;
            }

            $identifierTypeId = null;
            if (isset($relIdData['relatedIdentifierType'])) {
                $identifierTypeId = $this->getLookupId(
                    IdentifierType::class,
                    'slug',
                    $relIdData['relatedIdentifierType']
                );
            }

            $relationTypeId = null;
            if (isset($relIdData['relationType'])) {
                $relationTypeId = $this->getLookupId(
                    RelationType::class,
                    'slug',
                    $relIdData['relationType']
                );
            }

            // Skip if we couldn't resolve required types
            if ($identifierTypeId === null || $relationTypeId === null) {
                continue;
            }

            RelatedIdentifier::create([
                'resource_id' => $resource->id,
                'identifier' => $identifier,
                'identifier_type_id' => $identifierTypeId,
                'relation_type_id' => $relationTypeId,
                'related_metadata_scheme' => $relIdData['relatedMetadataScheme'] ?? null,
                'scheme_uri' => $relIdData['schemeUri'] ?? null,
                'scheme_type' => $relIdData['schemeType'] ?? null,
                'position' => $position + 1,
            ]);
        }
    }

    /**
     * Transform funding references from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $fundingReferences
     */
    private function transformFundingReferences(array $fundingReferences, Resource $resource): void
    {
        foreach ($fundingReferences as $fundingData) {
            $funderName = $fundingData['funderName'] ?? null;

            if ($funderName === null) {
                continue;
            }

            $funderIdentifierTypeId = null;
            if (isset($fundingData['funderIdentifierType'])) {
                $funderIdentifierTypeId = $this->getLookupId(
                    FunderIdentifierType::class,
                    'slug',
                    $fundingData['funderIdentifierType']
                );
            }

            FundingReference::create([
                'resource_id' => $resource->id,
                'funder_name' => $funderName,
                'funder_identifier' => $fundingData['funderIdentifier'] ?? null,
                'funder_identifier_type_id' => $funderIdentifierTypeId,
                'scheme_uri' => $fundingData['schemeUri'] ?? null,
                'award_number' => $fundingData['awardNumber'] ?? null,
                'award_uri' => $fundingData['awardUri'] ?? null,
                'award_title' => $fundingData['awardTitle'] ?? null,
            ]);
        }
    }

    /**
     * Transform rights/licenses from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $rightsList
     */
    private function transformRights(array $rightsList, Resource $resource): void
    {
        foreach ($rightsList as $rightsData) {
            $identifier = $rightsData['rightsIdentifier'] ?? null;

            if ($identifier === null) {
                // Try to find by name if no identifier
                $name = $rightsData['rights'] ?? null;
                if ($name !== null) {
                    $right = Right::where('name', $name)->first();
                    if ($right) {
                        $resource->rights()->attach($right->id);
                    }
                }

                continue;
            }

            // Find by SPDX identifier
            $right = Right::where('identifier', $identifier)->first();

            if ($right) {
                $resource->rights()->attach($right->id);
            }
        }
    }

    /**
     * Transform sizes from DataCite format.
     *
     * @param  array<int, string>  $sizes
     */
    private function transformSizes(array $sizes, Resource $resource): void
    {
        foreach ($sizes as $size) {
            if (empty($size)) {
                continue;
            }

            Size::create([
                'resource_id' => $resource->id,
                'value' => $size,
            ]);
        }
    }

    /**
     * Transform formats from DataCite format.
     *
     * @param  array<int, string>  $formats
     */
    private function transformFormats(array $formats, Resource $resource): void
    {
        foreach ($formats as $format) {
            if (empty($format)) {
                continue;
            }

            Format::create([
                'resource_id' => $resource->id,
                'value' => $format,
            ]);
        }
    }

    /**
     * Get the ID for a lookup table entry by slug/code.
     *
     * @param  class-string  $modelClass
     * @param  string  $field  The field to match (usually 'slug' or 'code')
     * @param  string  $value  The value to match
     */
    private function getLookupId(string $modelClass, string $field, string $value): ?int
    {
        $cacheKey = "{$modelClass}:{$field}";

        // Lazy-load the lookup cache
        if (! isset($this->lookupCache[$cacheKey])) {
            $this->lookupCache[$cacheKey] = $modelClass::pluck('id', $field)->toArray();
        }

        return $this->lookupCache[$cacheKey][$value] ?? null;
    }

    /**
     * Parse a person name string into family and given name parts.
     *
     * Handles formats like "Family, Given" or "Given Family".
     * Also handles names with suffixes like "Smith Jr., John" by detecting
     * common suffixes before the comma.
     *
     * @return array{family: string|null, given: string|null}
     */
    private function parsePersonName(string $name): array
    {
        // Common name suffixes that might appear before a comma
        // Used to detect cases like "Smith, Jr." which should NOT be split as family/given.
        //
        // Name parsing behavior:
        // - "Smith Jr., John" -> family="Smith Jr.", given="John" (suffix is part of family name)
        // - "Smith, John" -> family="Smith", given="John" (standard family/given format)
        // - "Smith, Jr." -> family="Smith, Jr.", given=null (suffix-only after comma, keep intact)
        // - "John Smith" -> family="Smith", given="John" (space-separated format)
        //
        // The suffix is intentionally kept with the family name to preserve the original data.
        // This ensures consistent person matching, as DataCite records typically store suffixes
        // with the family name.
        $suffixes = ['Jr.', 'Jr', 'Sr.', 'Sr', 'II', 'III', 'IV', 'V', 'PhD', 'Ph.D.', 'MD', 'M.D.'];

        // Try "Family, Given" format first
        if (str_contains($name, ',')) {
            $parts = explode(',', $name, 2);
            $potentialFamily = trim($parts[0]);
            $potentialGiven = isset($parts[1]) ? trim($parts[1]) : null;

            // Check if what we think is the given name is actually just a suffix.
            // This handles cases like "Smith, Jr." where "Jr." is not a given name.
            // For "Smith Jr., John", potentialGiven="John" which is not a suffix,
            // so we correctly parse it as family="Smith Jr.", given="John".
            $isSuffixOnly = false;
            if ($potentialGiven !== null) {
                foreach ($suffixes as $suffix) {
                    if (strcasecmp($potentialGiven, $suffix) === 0) {
                        $isSuffixOnly = true;
                        break;
                    }
                }
            }

            // If the part after comma is just a suffix, treat the whole name as family name.
            // This prevents "Smith, Jr." from being incorrectly split.
            if ($isSuffixOnly) {
                return [
                    'family' => $name,
                    'given' => null,
                ];
            }

            return [
                'family' => $potentialFamily,
                'given' => $potentialGiven,
            ];
        }

        // Try "Given Family" format
        $parts = explode(' ', $name);
        if (count($parts) > 1) {
            $familyName = array_pop($parts);

            return [
                'family' => $familyName,
                'given' => implode(' ', $parts),
            ];
        }

        // Single name, treat as family name
        return [
            'family' => $name,
            'given' => null,
        ];
    }

    /**
     * Parse a date string into a format suitable for database storage.
     *
     * Handles various DataCite date formats like YYYY, YYYY-MM, YYYY-MM-DD.
     * Validates that parsed dates are real calendar dates (e.g., rejects 2024-02-30).
     *
     * IMPORTANT: For year-only (YYYY) and year-month (YYYY-MM) formats, this method
     * normalizes to full dates. For start dates, it uses the beginning of the period
     * (YYYY-01-01 or YYYY-MM-01). For end dates, it uses the end of the period
     * (YYYY-12-31 or YYYY-MM-{last day of month}).
     *
     * @param  string|null  $date  The date string to parse
     * @param  bool  $isEndDate  Whether this is an end date (uses end of period instead of start)
     * @return string|null The parsed date in YYYY-MM-DD format, or null if invalid
     */
    private function parseDate(?string $date, bool $isEndDate = false): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }

        // Try to parse the date - DataCite allows various ISO 8601 formats
        // Note: Using [0-9] instead of \d for strict ASCII digit matching
        // as required by ISO 8601 standard (prevents matching Unicode digits)
        $date = trim($date);

        // Full date: YYYY-MM-DD
        if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/', $date, $matches)) {
            // Validate this is a real calendar date (e.g., reject 2024-02-30)
            if (checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
                return $date;
            }
            // Invalid calendar date - try Carbon as fallback, but log a warning
            // as this may introduce data quality issues (e.g., 2024-02-30 -> 2024-03-01)
            try {
                $correctedDate = \Carbon\Carbon::parse($date)->format('Y-m-d');
                Log::warning('DataCite import: Invalid calendar date corrected by Carbon', [
                    'original' => $date,
                    'corrected' => $correctedDate,
                ]);
                return $correctedDate;
            } catch (\Exception) {
                return null;
            }
        }

        // Year and month: YYYY-MM
        // For start dates: YYYY-MM-01 (first day of month)
        // For end dates: YYYY-MM-{last day} (last day of month)
        if (preg_match('/^([0-9]{4})-([0-9]{2})$/', $date, $matches)) {
            // Validate month is valid (01-12)
            $month = (int) $matches[2];
            $year = (int) $matches[1];
            if ($month >= 1 && $month <= 12) {
                if ($isEndDate) {
                    // Calculate last day of the month
                    $lastDay = (new \DateTime("{$year}-{$month}-01"))->format('t');
                    return sprintf('%04d-%02d-%s', $year, $month, $lastDay);
                }
                return $date . '-01';
            }
            return null;
        }

        // Year only: YYYY
        // For start dates: YYYY-01-01 (January 1st)
        // For end dates: YYYY-12-31 (December 31st)
        if (preg_match('/^([0-9]{4})$/', $date)) {
            return $isEndDate ? $date . '-12-31' : $date . '-01-01';
        }

        // Try to parse with Carbon for other formats
        try {
            return \Carbon\Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
