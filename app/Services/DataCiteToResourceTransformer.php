<?php

declare(strict_types=1);

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
use App\Models\RelatedItem;
use App\Models\RelatedItemContributor;
use App\Models\RelatedItemContributorAffiliation;
use App\Models\RelatedItemCreator;
use App\Models\RelatedItemCreatorAffiliation;
use App\Models\RelatedItemTitle;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceType;
use App\Models\Size;
use App\Models\Subject;
use App\Models\Title;
use App\Models\TitleType;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\Rights\ResourceRightsStorageService;
use App\Services\Xml\Sections\RightsSectionParser;
use App\Support\OrcidNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Saloon\XmlWrangler\XmlReader;

/**
 * Transforms DataCite API JSON responses into ERNIE Resource models.
 *
 * Maps all DataCite metadata fields to the local database schema.
 *
 * @see https://datacite-metadata-schema.readthedocs.io/en/4.7/
 */
class DataCiteToResourceTransformer
{
    private const PREPARED_MARKER = '__citation_labels_prepared';

    public function __construct(
        private ?RelatedIdentifierCitationLabelService $relatedIdentifierCitationLabelService = null,
        private ?ResourceRightsStorageService $resourceRightsStorage = null,
        private ?RorLookupService $rorLookupService = null,
        private ?SubjectBreadcrumbPathResolverService $subjectBreadcrumbPathResolver = null,
        private ?RightsSectionParser $xmlRightsParser = null,
    ) {}

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
        $preparedDoiData = $this->prepareDoiData($doiData);

        /** @var array<string, mixed> $attributes */
        $attributes = is_array($preparedDoiData['attributes'] ?? null)
            ? $preparedDoiData['attributes']
            : $preparedDoiData;

        unset($attributes[self::PREPARED_MARKER]);

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
            $this->transformRelatedItems($attributes['relatedItems'] ?? [], $resource);
            $this->transformFundingReferences($attributes['fundingReferences'] ?? [], $resource);
            $this->transformRights($attributes['rightsList'] ?? [], $resource);
            $this->transformSizes($attributes['sizes'] ?? [], $resource);
            $this->transformFormats($attributes['formats'] ?? [], $resource);

            return $resource;
        });
    }

    /**
     * @param  array<string, mixed>  $doiData
     * @return array<string, mixed>
     */
    public function prepareDoiData(array $doiData): array
    {
        if (($doiData[self::PREPARED_MARKER] ?? false) === true) {
            return $doiData;
        }

        if (is_array($doiData['attributes'] ?? null)) {
            $doiData['attributes'] = $this->prepareAttributes($doiData['attributes']);
            $doiData[self::PREPARED_MARKER] = true;

            return $doiData;
        }

        $preparedAttributes = $this->prepareAttributes($doiData);
        $preparedAttributes[self::PREPARED_MARKER] = true;

        return $preparedAttributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function prepareAttributes(array $attributes): array
    {
        $attributes = $this->preferOriginalXmlRights($attributes);

        $relatedIdentifiers = $attributes['relatedIdentifiers'] ?? null;

        if (! is_array($relatedIdentifiers)) {
            return $attributes;
        }

        $citationResolutionDeadline = microtime(true) + RelatedIdentifierCitationLabelService::DEFAULT_AGGREGATE_TIMEOUT_SECONDS;

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $identifier = isset($relatedIdentifier['relatedIdentifier'])
                ? trim((string) $relatedIdentifier['relatedIdentifier'])
                : '';

            if ($identifier === '') {
                unset($relatedIdentifiers[$index]['citationLabel']);

                continue;
            }

            $relatedIdentifiers[$index]['relatedIdentifier'] = $identifier;

            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;

                continue;
            }

            $resolvedCitationLabel = $this->citationLabelService()->resolveBestEffort(
                $identifier,
                (string) ($relatedIdentifier['relatedIdentifierType'] ?? ''),
                $citationResolutionDeadline,
            );

            if (is_string($resolvedCitationLabel) && trim($resolvedCitationLabel) !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = trim($resolvedCitationLabel);
            }
        }

        $attributes['relatedIdentifiers'] = $relatedIdentifiers;

        return $attributes;
    }

    /**
     * Prefer the embedded original DataCite XML rights over API-normalized rights.
     *
     * The `/resources` legacy import consumes the DataCite REST API. That API may
     * expose SPDX-like fields in `attributes.rightsList` even when the deposited
     * XML only contained a plain legacy rights statement. For import review, the
     * embedded original XML is the source of truth; SPDX completion belongs to
     * the assistant workflow.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function preferOriginalXmlRights(array $attributes): array
    {
        $xml = $this->decodedOriginalXml($attributes['xml'] ?? null);

        if ($xml === null) {
            return $attributes;
        }

        try {
            $rightsList = ($this->xmlRightsParser ??= new RightsSectionParser)
                ->parseRawRights(XmlReader::fromString($xml));
        } catch (\Throwable $exception) {
            Log::debug('Could not parse original DataCite XML rights during import; using API rightsList fallback.', [
                'doi' => $attributes['doi'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return $attributes;
        }

        if ($rightsList === []) {
            return $attributes;
        }

        $attributes['rightsList'] = array_map(
            function (array $statement): array {
                $statement['source'] = 'datacite-import';

                return $statement;
            },
            $rightsList,
        );

        return $attributes;
    }

    private function decodedOriginalXml(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $decoded = base64_decode($value, true);
        $xml = $decoded === false ? $value : $decoded;

        return str_contains($xml, '<') ? $xml : null;
    }

    private function citationLabelService(): RelatedIdentifierCitationLabelService
    {
        return $this->relatedIdentifierCitationLabelService ??= app(RelatedIdentifierCitationLabelService::class);
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
     * For GFZ Data Services: Always uses the default publisher with full DataCite 4.6 metadata,
     * enriching older records that may only have the name field.
     *
     * For other publishers: Preserves the original metadata from DataCite.
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

        // Special case: If publisher is "GFZ Data Services", use the default publisher
        // with full DataCite 4.6 metadata (enriches older records that only have the name)
        if ($publisherName === 'GFZ Data Services') {
            $defaultPublisher = Publisher::getDefault();
            if ($defaultPublisher !== null) {
                return $defaultPublisher->id;
            }
        }

        $existing = Publisher::where('name', $publisherName)->first();

        if ($existing) {
            return $existing->id;
        }

        // Create new publisher (for non-GFZ publishers)
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
     * In DataCite XML, MainTitle has no titleType attribute - it's simply omitted.
     * In the database, all titles must reference a TitleType record, including MainTitle.
     *
     * @param  array<int, array<string, mixed>>  $titles
     */
    private function transformTitles(array $titles, Resource $resource): void
    {
        // Pre-fetch MainTitle ID for titles without titleType attribute
        $mainTitleId = $this->resolveTitleTypeId('MainTitle', 'Main Title');

        foreach ($titles as $titleData) {
            $titleValue = $titleData['title'] ?? null;

            if ($titleValue === null) {
                continue;
            }

            // Get title type - default to MainTitle if not specified (DataCite convention)
            $titleType = $titleData['titleType'] ?? null;

            if ($titleType === null || $titleType === '') {
                // No titleType in XML means it's the MainTitle
                $titleTypeId = $mainTitleId;
            } else {
                $titleTypeId = $this->getLookupId(TitleType::class, 'slug', $titleType);

                // Fall back to Other if specific type not found
                if ($titleTypeId === null) {
                    $titleTypeId = $this->resolveTitleTypeId('Other', 'Other');
                }
            }

            Title::create([
                'resource_id' => $resource->id,
                'value' => $titleValue,
                'title_type_id' => $titleTypeId,
                'language' => $titleData['lang'] ?? null,
            ]);
        }
    }

    private function resolveTitleTypeId(string $slug, string $name): int
    {
        $titleTypeId = $this->getLookupId(TitleType::class, 'slug', $slug);

        if ($titleTypeId !== null) {
            return $titleTypeId;
        }

        $titleType = TitleType::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'is_active' => true,
                'is_elmo_active' => true,
            ],
        );

        unset($this->lookupCache[TitleType::class.':slug']);

        return (int) $titleType->id;
    }

    /**
     * Transform creators from DataCite format.
     *
     * @param  array<int, array<string, mixed>>  $creators
     */
    private function transformCreators(array $creators, Resource $resource): void
    {
        foreach ($creators as $position => $creatorData) {
            // Skip records without any name information
            if (! $this->hasAnyName($creatorData)) {
                Log::debug('Skipping creator without any name data', [
                    'resource_id' => $resource->id,
                    'position' => $position,
                    'name' => $creatorData['name'] ?? null,
                    'familyName' => $creatorData['familyName'] ?? null,
                    'givenName' => $creatorData['givenName'] ?? null,
                ]);

                continue;
            }

            $declaredNameType = $this->normaliseNameType($creatorData['nameType'] ?? null);
            $nameType = $this->resolveNameType($creatorData);
            $this->logNameTypeOverride('creator', $resource, $position, $creatorData, $declaredNameType, $nameType);

            try {
                if ($nameType === 'Organizational') {
                    $entity = $this->findOrCreateInstitution($creatorData);
                    $entityType = Institution::class;
                } else {
                    $entity = $this->findOrCreatePerson($creatorData);
                    $entityType = Person::class;
                }
            } catch (\InvalidArgumentException $e) {
                Log::debug('Skipping creator with unresolvable name', [
                    'resource_id' => $resource->id,
                    'position' => $position,
                    'name' => $creatorData['name'] ?? null,
                    'familyName' => $creatorData['familyName'] ?? null,
                    'givenName' => $creatorData['givenName'] ?? null,
                    'reason' => $e->getMessage(),
                ]);

                continue;
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
            // Skip records without any name information
            if (! $this->hasAnyName($contributorData)) {
                Log::debug('Skipping contributor without any name data', [
                    'resource_id' => $resource->id,
                    'position' => $position,
                    'name' => $contributorData['name'] ?? null,
                    'familyName' => $contributorData['familyName'] ?? null,
                    'givenName' => $contributorData['givenName'] ?? null,
                ]);

                continue;
            }

            $declaredNameType = $this->normaliseNameType($contributorData['nameType'] ?? null);
            $nameType = $this->resolveNameType($contributorData);
            $this->logNameTypeOverride('contributor', $resource, $position, $contributorData, $declaredNameType, $nameType);

            try {
                if ($nameType === 'Organizational') {
                    $entity = $this->findOrCreateInstitution($contributorData);
                    $entityType = Institution::class;
                } else {
                    $entity = $this->findOrCreatePerson($contributorData);
                    $entityType = Person::class;
                }
            } catch (\InvalidArgumentException $e) {
                Log::debug('Skipping contributor with unresolvable name', [
                    'resource_id' => $resource->id,
                    'position' => $position,
                    'name' => $contributorData['name'] ?? null,
                    'familyName' => $contributorData['familyName'] ?? null,
                    'givenName' => $contributorData['givenName'] ?? null,
                    'reason' => $e->getMessage(),
                ]);

                continue;
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
                'position' => $position + 1,
            ]);

            // Attach contributor type via pivot table
            $resourceContributor->contributorTypes()->sync([$contributorTypeId]);

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
        $familyName = isset($data['familyName']) ? trim((string) $data['familyName']) : null;
        $givenName = isset($data['givenName']) ? trim((string) $data['givenName']) : null;

        // Treat empty strings as null after trimming
        if ($familyName === '') {
            $familyName = null;
        }
        if ($givenName === '') {
            $givenName = null;
        }

        // If no structured name, try to parse from 'name' field
        if ($familyName === null && isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name !== '') {
                $parts = $this->parsePersonName($name);
                $familyName = $parts['family'] !== null ? trim($parts['family']) : null;
                $givenName = $parts['given'] !== null ? trim($parts['given']) : null;

                if ($familyName === '') {
                    $familyName = null;
                }
                if ($givenName === '') {
                    $givenName = null;
                }
            }
        }

        // Extract ORCID from name identifiers
        $orcid = null;
        $scheme = null;
        $schemeUri = null;

        $nameIdentifiers = $data['nameIdentifiers'] ?? [];

        if (is_array($nameIdentifiers)) {
            foreach ($nameIdentifiers as $nameId) {
                if (
                    ! is_array($nameId)
                    || strtolower(trim((string) ($nameId['nameIdentifierScheme'] ?? ''))) !== 'orcid'
                ) {
                    continue;
                }

                $identifier = isset($nameId['nameIdentifier'])
                    ? trim((string) $nameId['nameIdentifier'])
                    : '';

                if ($identifier === '' || ! OrcidNormalizer::isValid($identifier)) {
                    continue;
                }

                $orcid = OrcidNormalizer::toUrl($identifier);
                $scheme = 'ORCID';
                $schemeUri = 'https://orcid.org';
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

        // Safety guard: family_name is NOT NULL in the database
        if ($familyName === null || trim($familyName) === '') {
            throw new \InvalidArgumentException(
                'Cannot create Person: family_name is required but was empty.'
            );
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
     * Resolve the local party type from DataCite data.
     *
     * Legacy DataCite records can mark institutions as Personal. Identifier
     * schemes and organization-looking full names win over the raw nameType value.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveNameType(array $data): string
    {
        $declaredNameType = $this->normaliseNameType($data['nameType'] ?? null);

        if ($this->hasNameIdentifierScheme($data, 'ROR')) {
            return 'Organizational';
        }

        if ($this->hasNameIdentifierScheme($data, 'ORCID')) {
            return 'Personal';
        }

        $name = isset($data['name']) ? trim((string) $data['name']) : '';

        if ($name === '') {
            return $this->hasStructuredPersonName($data) ? 'Personal' : ($declaredNameType ?? 'Personal');
        }

        if ($this->looksLikeOrganization($name, false)) {
            return 'Organizational';
        }

        if ($this->hasStructuredPersonName($data)) {
            return 'Personal';
        }

        if ($declaredNameType === 'Organizational') {
            return 'Organizational';
        }

        if ($this->looksLikePersonName($name)) {
            return 'Personal';
        }

        return $declaredNameType ?? 'Organizational';
    }

    private function normaliseNameType(mixed $nameType): ?string
    {
        if (! is_string($nameType)) {
            return null;
        }

        return match (strtolower(trim($nameType))) {
            'personal' => 'Personal',
            'organizational' => 'Organizational',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasStructuredPersonName(array $data): bool
    {
        return (isset($data['familyName']) && trim((string) $data['familyName']) !== '')
            || (isset($data['givenName']) && trim((string) $data['givenName']) !== '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasNameIdentifierScheme(array $data, string $expectedScheme): bool
    {
        $nameIdentifiers = $data['nameIdentifiers'] ?? [];

        if (! is_array($nameIdentifiers)) {
            return false;
        }

        foreach ($nameIdentifiers as $nameIdentifier) {
            if (! is_array($nameIdentifier)) {
                continue;
            }

            $scheme = isset($nameIdentifier['nameIdentifierScheme'])
                ? strtolower(trim((string) $nameIdentifier['nameIdentifierScheme']))
                : '';
            $identifier = isset($nameIdentifier['nameIdentifier'])
                ? strtolower(trim((string) $nameIdentifier['nameIdentifier']))
                : '';

            if ($identifier === '') {
                continue;
            }

            if ($expectedScheme === 'ORCID') {
                if ($scheme === 'orcid' || str_contains($identifier, 'orcid.org/')) {
                    return OrcidNormalizer::isValid($identifier);
                }

                continue;
            }

            if ($expectedScheme === 'ROR') {
                if ($this->canonicalRorIdentifier($identifier, $scheme) !== null) {
                    return true;
                }

                continue;
            }

            if ($scheme === strtolower($expectedScheme)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalRorIdentifier(string $identifier, string $scheme): ?string
    {
        $identifier = trim($identifier);
        $scheme = strtolower(trim($scheme));

        if ($identifier === '') {
            return null;
        }

        if ($scheme !== 'ror' && ! str_contains(strtolower($identifier), 'ror.org/')) {
            return null;
        }

        return $this->rorLookupService()->canonicalise($identifier);
    }

    private function rorLookupService(): RorLookupService
    {
        return $this->rorLookupService ??= app(RorLookupService::class);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function logNameTypeOverride(
        string $partyRole,
        Resource $resource,
        int $position,
        array $data,
        ?string $declaredNameType,
        string $resolvedNameType,
    ): void {
        if ($declaredNameType === null || $declaredNameType === $resolvedNameType) {
            return;
        }

        $persistedPosition = $position + 1;

        Log::debug('Corrected DataCite party nameType during import', [
            'resource_id' => $resource->id,
            'doi' => $resource->doi,
            'role' => $partyRole,
            'position' => $persistedPosition,
            'source_index' => $position,
            'name' => $data['name'] ?? null,
            'declared_name_type' => $declaredNameType,
            'resolved_name_type' => $resolvedNameType,
        ]);
    }

    /**
     * Check if a name string looks like an organization based on common keywords.
     * Uses word-boundary matching to avoid substring false positives
     * (e.g. "inc" must not match inside "Vincenzo").
     */
    private function looksLikeOrganization(string $name, bool $includeWeakShapeSignals = true): bool
    {
        $orgKeywords = [
            'institute', 'institution', 'university', 'universität',
            'centre', 'center', 'laboratory', 'laboratoire',
            'agency', 'organization', 'organisation', 'foundation',
            'corporation', 'consortium', 'council', 'commission',
            'department', 'ministry', 'bureau', 'authority',
            'association', 'society', 'academy', 'museum',
            'library', 'service', 'survey', 'observatory',
            'government', 'administration', 'directorate',
            'geoscience', 'geophysical', 'geological', 'geomagnetic',
            'geoforschungszentrum', 'forschungszentrum',
            'meteorological', 'seismology', 'earthquake', 'space',
            'research', 'resources', 'branch', 'national', 'royal',
            'intermagnet', 'secretariat',
            'observatorio', 'institut', 'instituto', 'ecole', 'universidad',
            'gmbh', 'ltd', 'inc', 'e\.v\.', 'helmholtz',
        ];

        $pattern = '/\b('.implode('|', $orgKeywords).')\b/iu';

        if ((bool) preg_match($pattern, $name)) {
            return true;
        }

        if (preg_match('/^[A-Z0-9&.\- ]{3,}$/', $name) === 1) {
            return true;
        }

        if (! str_contains($name, ',') && preg_match('/\([A-Za-z][A-Za-z .&-]{2,}\)\s*$/', $name) === 1) {
            return true;
        }

        if (! $includeWeakShapeSignals) {
            return false;
        }

        return $this->wordCount($name) >= 4
            && preg_match('/(?:[\/&]|\b(?:of|for|and|de|del|du|des|der|la|le|fuer|et)\b)/iu', $name) === 1;
    }

    private function looksLikePersonName(string $name): bool
    {
        $parts = $this->parsePersonName($name);

        if ($parts['given'] !== null && trim($parts['given']) !== '') {
            return true;
        }

        return $parts['family'] !== null && str_contains($name, ',');
    }

    private function wordCount(string $name): int
    {
        if (preg_match_all('/[\p{L}\p{N}]+/u', $name, $matches) === false) {
            return 0;
        }

        return count($matches[0]);
    }

    /**
     * Check if a creator/contributor record has any name information.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasAnyName(array $data): bool
    {
        return (isset($data['name']) && trim((string) $data['name']) !== '')
            || (isset($data['familyName']) && trim((string) $data['familyName']) !== '')
            || (isset($data['givenName']) && trim((string) $data['givenName']) !== '');
    }

    /**
     * Find or create an Institution entity.
     *
     * @param  array<string, mixed>  $data
     */
    private function findOrCreateInstitution(array $data): Institution
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Cannot create Institution: name is required but was empty.');
        }

        // Extract ROR from name identifiers
        $ror = null;
        $scheme = null;
        $schemeUri = null;

        $nameIdentifiers = $data['nameIdentifiers'] ?? [];

        if (is_array($nameIdentifiers)) {
            foreach ($nameIdentifiers as $nameId) {
                if (! is_array($nameId)) {
                    continue;
                }

                $identifier = isset($nameId['nameIdentifier'])
                    ? trim((string) $nameId['nameIdentifier'])
                    : '';
                $nameIdentifierScheme = isset($nameId['nameIdentifierScheme'])
                    ? trim((string) $nameId['nameIdentifierScheme'])
                    : '';

                $canonicalRor = $this->canonicalRorIdentifier($identifier, $nameIdentifierScheme);

                if ($canonicalRor === null) {
                    continue;
                }

                $ror = $canonicalRor;
                $scheme = 'ROR';
                $schemeUri = 'https://ror.org';
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

            $rawSubjectValue = is_string($value) ? $value : null;
            $subjectValue = $rawSubjectValue !== null ? (SubjectBreadcrumbPath::normalize($rawSubjectValue) ?? $value) : $value;
            $subjectScheme = $subjectData['subjectScheme'] ?? null;
            $schemeUri = $this->filledString($subjectData['schemeUri'] ?? null);
            $valueUri = $this->filledString($subjectData['valueUri'] ?? null);
            $classificationCode = $subjectData['classificationCode'] ?? null;
            $breadcrumbPath = SubjectBreadcrumbPath::preferredPath(null, $rawSubjectValue);

            if ($valueUri === null || $schemeUri === null) {
                $resolvedKeyword = $this->subjectPathResolver()->resolveKeywordFromPath(
                    is_string($subjectScheme) ? $subjectScheme : null,
                    $rawSubjectValue,
                );

                if ($resolvedKeyword !== null) {
                    $subjectScheme = $resolvedKeyword['scheme'];
                    $schemeUri = $schemeUri ?? $resolvedKeyword['schemeURI'];
                    $valueUri = $valueUri ?? $resolvedKeyword['id'];
                    $breadcrumbPath = $resolvedKeyword['path'];
                }
            }

            $breadcrumbPath = $breadcrumbPath ?? $this->subjectPathResolver()->resolve(
                is_string($subjectScheme) ? $subjectScheme : null,
                $valueUri,
                is_string($classificationCode) || is_numeric($classificationCode) ? (string) $classificationCode : null,
                $rawSubjectValue,
            );

            $schemeUri = $schemeUri ?? $this->subjectPathResolver()->resolveSchemeUri(
                is_string($subjectScheme) ? $subjectScheme : null,
            );

            Subject::create([
                'resource_id' => $resource->id,
                'value' => $subjectValue,
                'language' => $subjectData['lang'] ?? 'en',
                'subject_scheme' => $subjectScheme,
                'scheme_uri' => $schemeUri,
                'value_uri' => $valueUri,
                'classification_code' => $classificationCode,
                'breadcrumb_path' => $breadcrumbPath,
            ]);
        }
    }

    private function subjectPathResolver(): SubjectBreadcrumbPathResolverService
    {
        return $this->subjectBreadcrumbPathResolver ??= app(SubjectBreadcrumbPathResolverService::class);
    }

    private function filledString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * Transform dates from DataCite format.
     *
     * Issue #371: If no 'Created' date is present in the DataCite response,
     * a fallback 'Created' date with the current date is automatically added.
     *
     * @param  array<int, array<string, mixed>>  $dates
     */
    private function transformDates(array $dates, Resource $resource): void
    {
        $hasCreatedDate = false;

        foreach ($dates as $dateData) {
            $date = $dateData['date'] ?? null;

            if ($date === null) {
                continue;
            }

            $dateType = $dateData['dateType'] ?? null;
            $dateTypeId = null;
            if ($dateType !== null) {
                $dateTypeId = $this->getLookupId(DateType::class, 'slug', $dateType);

                // Track if we have a 'Created' date
                if (strtolower((string) $dateType) === 'created') {
                    $hasCreatedDate = true;
                }
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
                // Single date (not a range): Always use isEndDate=false (start of period).
                // This follows the DataCite convention where incomplete dates represent
                // the earliest possible date (e.g., "2020" means "2020-01-01").
                // The date type (Issued, Collected, etc.) does NOT influence this decision
                // because DataCite metadata semantics treat all partial dates consistently
                // as the start of the period they represent.
                $dateValue = $this->parseDate($date, false);
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

        // Issue #371: If no 'Created' date was imported, add current date as fallback
        // This ensures every imported resource has a valid creation date
        if (! $hasCreatedDate) {
            $createdDateTypeId = $this->getLookupId(DateType::class, 'slug', 'Created');

            if ($createdDateTypeId !== null) {
                ResourceDate::create([
                    'resource_id' => $resource->id,
                    'date_value' => now()->format('Y-m-d'),
                    'start_date' => null,
                    'end_date' => null,
                    'date_type_id' => $createdDateTypeId,
                    'date_information' => null,
                ]);
            }
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
            $polygon = $geoData['geoLocationPolygon'] ?? null;

            // Transform polygon points to storage format
            $polygonPoints = null;
            $inPolygonPointLon = null;
            $inPolygonPointLat = null;

            if ($polygon !== null && ! empty($polygon['polygonPoints'])) {
                // Filter out points with missing coordinates instead of coercing to 0
                $validPoints = array_filter(
                    $polygon['polygonPoints'],
                    fn (array $p): bool => $this->normaliseCoordinate($p['pointLongitude'] ?? null) !== null
                        && $this->normaliseCoordinate($p['pointLatitude'] ?? null) !== null,
                );

                // Only store polygon if at least 3 valid points remain
                if (count($validPoints) >= 3) {
                    $polygonPoints = array_values(array_map(fn (array $p): array => [
                        'longitude' => $this->normaliseCoordinate($p['pointLongitude']),
                        'latitude' => $this->normaliseCoordinate($p['pointLatitude']),
                    ], $validPoints));
                }

                if (isset($polygon['inPolygonPoint'])) {
                    $inPolygonPointLon = $this->normaliseCoordinate($polygon['inPolygonPoint']['pointLongitude'] ?? null);
                    $inPolygonPointLat = $this->normaliseCoordinate($polygon['inPolygonPoint']['pointLatitude'] ?? null);
                }
            }

            // Determine geo_type based on available (valid) data
            $geoType = null;
            if ($polygonPoints !== null) {
                $geoType = 'polygon';
            } elseif ($box !== null) {
                $geoType = 'box';
            } elseif ($point !== null) {
                $geoType = 'point';
            }

            GeoLocation::create([
                'resource_id' => $resource->id,
                'geo_type' => $geoType,
                'place' => $geoData['geoLocationPlace'] ?? null,
                'point_longitude' => $this->normaliseCoordinate($point['pointLongitude'] ?? null),
                'point_latitude' => $this->normaliseCoordinate($point['pointLatitude'] ?? null),
                'west_bound_longitude' => $this->normaliseCoordinate($box['westBoundLongitude'] ?? null),
                'east_bound_longitude' => $this->normaliseCoordinate($box['eastBoundLongitude'] ?? null),
                'south_bound_latitude' => $this->normaliseCoordinate($box['southBoundLatitude'] ?? null),
                'north_bound_latitude' => $this->normaliseCoordinate($box['northBoundLatitude'] ?? null),
                'polygon_points' => $polygonPoints,
                'in_polygon_point_longitude' => $inPolygonPointLon,
                'in_polygon_point_latitude' => $inPolygonPointLat,
            ]);
        }
    }

    private function normaliseCoordinate(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '') {
                return null;
            }
        }

        return is_numeric($value) ? (float) $value : null;
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
                'relation_type_information' => $relIdData['relationTypeInformation'] ?? null,
                'citation_label' => isset($relIdData['citationLabel']) && trim((string) $relIdData['citationLabel']) !== ''
                    ? trim((string) $relIdData['citationLabel'])
                    : null,
                'related_metadata_scheme' => $relIdData['relatedMetadataScheme'] ?? null,
                'scheme_uri' => $relIdData['schemeUri'] ?? null,
                'scheme_type' => $relIdData['schemeType'] ?? null,
                'position' => $position + 1,
            ]);
        }
    }

    /**
     * Transform related items (DataCite 4.7) from DataCite JSON into RelatedItem + children.
     *
     * @param  array<int, array<string, mixed>>  $relatedItems
     */
    private function transformRelatedItems(array $relatedItems, Resource $resource): void
    {
        foreach ($relatedItems as $position => $riData) {
            $relationTypeSlug = $riData['relationType'] ?? null;
            $relatedItemType = $riData['relatedItemType'] ?? null;

            if (! is_string($relationTypeSlug) || ! is_string($relatedItemType)) {
                continue;
            }

            $relationTypeId = $this->getLookupId(RelationType::class, 'slug', $relationTypeSlug);
            if ($relationTypeId === null) {
                continue;
            }

            // DataCite 4.7 stores the identifier as a nested object or a simple string
            $identifier = null;
            $identifierType = null;
            $relatedMetadataScheme = null;
            $relatedSchemeUri = null;
            $relatedSchemeType = null;
            if (isset($riData['relatedItemIdentifier'])) {
                $raw = $riData['relatedItemIdentifier'];
                if (is_array($raw)) {
                    $identifier = is_string($raw['relatedItemIdentifier'] ?? null) ? $raw['relatedItemIdentifier'] : null;
                    $identifierType = is_string($raw['relatedItemIdentifierType'] ?? null) ? $raw['relatedItemIdentifierType'] : null;
                    // Optional DataCite 4.7 metadata-scheme attributes — read
                    // both `schemeURI` (DataCite spec casing) and `schemeUri`
                    // (camelCase variant emitted by some clients) so the
                    // value survives round-trips regardless of source casing.
                    $relatedMetadataScheme = is_string($raw['relatedMetadataScheme'] ?? null) ? $raw['relatedMetadataScheme'] : null;
                    $relatedSchemeUri = is_string($raw['schemeURI'] ?? null)
                        ? $raw['schemeURI']
                        : (is_string($raw['schemeUri'] ?? null) ? $raw['schemeUri'] : null);
                    $relatedSchemeType = is_string($raw['schemeType'] ?? null) ? $raw['schemeType'] : null;
                } elseif (is_string($raw)) {
                    $identifier = $raw;
                    $identifierType = is_string($riData['relatedItemIdentifierType'] ?? null) ? $riData['relatedItemIdentifierType'] : null;
                }
            }

            $relatedItem = RelatedItem::create([
                'resource_id' => $resource->id,
                'related_item_type' => $relatedItemType,
                'relation_type_id' => $relationTypeId,
                'identifier' => $identifier,
                'identifier_type' => $identifierType,
                'related_metadata_scheme' => $relatedMetadataScheme,
                'scheme_uri' => $relatedSchemeUri,
                'scheme_type' => $relatedSchemeType,
                'publication_year' => isset($riData['publicationYear']) ? (int) $riData['publicationYear'] : null,
                'volume' => $riData['volume'] ?? null,
                'issue' => $riData['issue'] ?? null,
                'number' => $riData['number'] ?? null,
                'number_type' => $riData['numberType'] ?? null,
                'first_page' => $riData['firstPage'] ?? null,
                'last_page' => $riData['lastPage'] ?? null,
                'publisher' => $riData['publisher'] ?? null,
                'edition' => $riData['edition'] ?? null,
                'position' => $position,
            ]);

            // Titles
            foreach (($riData['titles'] ?? []) as $titlePos => $titleData) {
                if (! is_array($titleData) || ! isset($titleData['title']) || ! is_string($titleData['title'])) {
                    continue;
                }
                RelatedItemTitle::create([
                    'related_item_id' => $relatedItem->id,
                    'title' => $titleData['title'],
                    'title_type' => is_string($titleData['titleType'] ?? null) ? $titleData['titleType'] : 'MainTitle',
                    'position' => $titlePos,
                ]);
            }

            // Creators
            foreach (($riData['creators'] ?? []) as $cPos => $creatorData) {
                if (! is_array($creatorData)) {
                    continue;
                }
                $creator = $this->createRelatedItemPerson($creatorData, $relatedItem->id, $cPos, RelatedItemCreator::class);
                $this->createRelatedItemAffiliations($creatorData, $creator->id, RelatedItemCreatorAffiliation::class, 'related_item_creator_id');
            }

            // Contributors
            foreach (($riData['contributors'] ?? []) as $cPos => $contribData) {
                if (! is_array($contribData)) {
                    continue;
                }
                $contributor = $this->createRelatedItemPerson($contribData, $relatedItem->id, $cPos, RelatedItemContributor::class);
                $this->createRelatedItemAffiliations($contribData, $contributor->id, RelatedItemContributorAffiliation::class, 'related_item_contributor_id');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  class-string<RelatedItemCreator|RelatedItemContributor>  $modelClass
     * @return RelatedItemCreator|RelatedItemContributor
     */
    private function createRelatedItemPerson(array $data, int $relatedItemId, int $position, string $modelClass)
    {
        $nameIdentifier = null;
        $nameIdentifierScheme = null;
        $schemeUri = null;

        if (is_array($data['nameIdentifiers'] ?? null) && $data['nameIdentifiers'] !== []) {
            $first = $data['nameIdentifiers'][0] ?? null;
            if (is_array($first)) {
                $nameIdentifier = is_string($first['nameIdentifier'] ?? null) ? $first['nameIdentifier'] : null;
                $nameIdentifierScheme = is_string($first['nameIdentifierScheme'] ?? null) ? $first['nameIdentifierScheme'] : null;
                $schemeUri = is_string($first['schemeUri'] ?? null) ? $first['schemeUri'] : null;
            }
        }

        $attrs = [
            'related_item_id' => $relatedItemId,
            'name_type' => is_string($data['nameType'] ?? null) ? $data['nameType'] : 'Personal',
            'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
            'given_name' => $data['givenName'] ?? null,
            'family_name' => $data['familyName'] ?? null,
            'name_identifier' => $nameIdentifier,
            'name_identifier_scheme' => $nameIdentifierScheme,
            'scheme_uri' => $schemeUri,
            'position' => $position,
        ];

        if ($modelClass === RelatedItemContributor::class) {
            $attrs['contributor_type'] = is_string($data['contributorType'] ?? null) ? $data['contributorType'] : 'Other';
        }

        /** @var RelatedItemCreator|RelatedItemContributor $model */
        $model = $modelClass::create($attrs);

        return $model;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  class-string<RelatedItemCreatorAffiliation|RelatedItemContributorAffiliation>  $affiliationClass
     */
    private function createRelatedItemAffiliations(array $data, int $personId, string $affiliationClass, string $foreignKey): void
    {
        if (! is_array($data['affiliation'] ?? null)) {
            return;
        }

        foreach ($data['affiliation'] as $pos => $aff) {
            if (! is_array($aff) || ! isset($aff['name']) || ! is_string($aff['name']) || $aff['name'] === '') {
                continue;
            }
            $affiliationClass::create([
                $foreignKey => $personId,
                'name' => $aff['name'],
                'affiliation_identifier' => is_string($aff['affiliationIdentifier'] ?? null) ? $aff['affiliationIdentifier'] : null,
                'scheme' => is_string($aff['affiliationIdentifierScheme'] ?? null) ? $aff['affiliationIdentifierScheme'] : null,
                // DataCite 4.7 affiliations may carry an optional `schemeUri`
                // alongside the identifier scheme. Persist it so JSON exports
                // can round-trip the value (the column exists on both
                // affiliation tables).
                'scheme_uri' => is_string($aff['schemeUri'] ?? null) ? $aff['schemeUri'] : null,
                'position' => $pos,
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
        // Store each incoming DataCite rights node as a resource_rights row. If
        // the local catalog has an exact identifier/name/URI match the row is
        // linked immediately; otherwise the raw statement remains unresolved so
        // the SPDX assistant can offer a reviewer-visible suggestion later.
        ($this->resourceRightsStorage ??= app(ResourceRightsStorageService::class))
            ->persistImportedStatements($resource, $rightsList, 'datacite-import', $resource->language?->code);
    }

    /**
     * Transform sizes from DataCite format.
     *
     * Parses free-text size strings (e.g., "1.5 GB", "1000 files") into
     * structured columns. If the string matches a "number unit" pattern,
     * it is split into numeric_value and unit. Otherwise, the entire
     * string is stored in unit so the export_string accessor returns it unchanged.
     *
     * @param  array<int, string>  $sizes
     */
    private function transformSizes(array $sizes, Resource $resource): void
    {
        foreach ($sizes as $size) {
            if (empty($size)) {
                continue;
            }

            $size = trim($size);

            // Try to parse "number unit" pattern (e.g., "1.5 GB", "1000 files", "1.5GB").
            // We only split when the trailing unit part actually looks like a
            // unit token; otherwise free-text values such as
            // "440MB/day; about 80 active stations" would be partially
            // misinterpreted (numeric prefix stripped) and the long remainder
            // would overflow sizes.unit. The exact contract for the tail is
            // documented on `looksLikeSizeUnit()` below and exercised by
            // tests/pest/Feature/Services/DataCiteToResourceTransformerSizesTest.php.
            if (
                preg_match('/^([\d.]+)\s*(.+)$/', $size, $matches) === 1
                && $this->looksLikeSizeUnit($matches[2])
            ) {
                Size::create([
                    'resource_id' => $resource->id,
                    'numeric_value' => $matches[1],
                    'unit' => $matches[2],
                ]);
            } else {
                // Store as unit only so export_string returns the original string
                Size::create([
                    'resource_id' => $resource->id,
                    'unit' => $size,
                ]);
            }
        }
    }

    /**
     * Decide whether the captured "tail" of a size string looks like a real
     * unit token (e.g. "GB", "pages", "Mb/s") rather than a sentence fragment.
     *
     * A candidate is treated as a unit when ALL of the following hold:
     *  - it is at most 50 characters long (matches the historical column size
     *    and keeps "unit" semantically narrow);
     *  - it consists of at most three whitespace-separated tokens;
     *  - it contains no sentence punctuation (".", ",", ";", ":").
     *
     * Anything else is preserved verbatim by the caller in `sizes.unit` so the
     * original DataCite free-text wording survives the round-trip.
     */
    private function looksLikeSizeUnit(string $candidate): bool
    {
        if (mb_strlen($candidate) > 50) {
            return false;
        }

        if (preg_match('/[.,;:]/', $candidate) === 1) {
            return false;
        }

        return str_word_count($candidate) <= 3;
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
                $correctedDate = Carbon::parse($date)->format('Y-m-d');
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

                return $date.'-01';
            }

            return null;
        }

        // Year only: YYYY
        // For start dates: YYYY-01-01 (January 1st)
        // For end dates: YYYY-12-31 (December 31st)
        if (preg_match('/^([0-9]{4})$/', $date)) {
            return $isEndDate ? $date.'-12-31' : $date.'-01-01';
        }

        // Try to parse with Carbon for other formats
        try {
            return Carbon::parse($date)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
