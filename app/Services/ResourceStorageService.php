<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContributorType;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\FunderIdentifierType;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Language;
use App\Models\Person;
use App\Models\Publisher;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\TitleType;
use App\Services\Citations\RelatedIdentifierCitationLabelService;
use App\Services\Citations\RelatedItemStorageService;
use App\Services\Entities\AffiliationService;
use App\Services\Entities\InstitutionService;
use App\Services\Entities\PersonService;
use App\Services\Rights\CustomRightCatalogService;
use App\Services\Rights\ResourceRightsStorageService;
use App\Support\OrcidNormalizer;
use App\Support\SubjectBreadcrumbPath;
use App\Support\UriHelper;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResourceStorageService
{
    private const CONTACT_FIELD_MAX_LENGTH = 255;

    /** @var array<string>|null Cached Contact Person name/slug for role matching */
    private ?array $contactPersonNames = null;

    public function __construct(
        protected DescriptionFormattingService $descriptionFormattingService,
        protected PersonService $personService,
        protected InstitutionService $institutionService,
        protected AffiliationService $affiliationService,
        protected PortalKeywordCacheInvalidationService $portalKeywordCacheInvalidationService,
        protected RorLookupService $rorLookupService,
        protected RelatedIdentifierCitationLabelService $relatedIdentifierCitationLabelService,
        protected RelatedItemStorageService $relatedItemStorage,
        protected ResourceRightsStorageService $resourceRightsStorage,
        protected CustomRightCatalogService $customRightCatalog,
        protected SubjectBreadcrumbPathResolverService $subjectBreadcrumbPathResolver,
    ) {}

    /**
     * Store or update a Resource and all related entities (creators, contributors, affiliations, etc.)
     *
     * @param  array<string, mixed>  $data  Validated request data
     * @param  int|null  $userId  ID of the user performing the operation
     * @return array{0: Resource, 1: bool} Returns [$resource, $isUpdate]
     *
     * @throws QueryException
     * @throws ValidationException
     */
    #[\NoDiscard('Stored resource and update flag must be used')]
    public function store(array $data, ?int $userId = null): array
    {
        $data = $this->prepareDataForStorage($data);

        return DB::transaction(function () use ($data, $userId): array {
            $languageId = null;

            if (! empty($data['language'])) {
                $languageId = Language::query()
                    ->where('code', $data['language'])
                    ->value('id');
            }

            $doi = $data['doi'] ?? null;
            if ($doi !== null && $doi !== '') {
                $doi = app(DoiSuggestionService::class)->normalizeDoi($doi);
                if ($doi === '') {
                    $doi = null;
                }
            }

            $attributes = [
                'doi' => $doi,
                'publication_year' => $data['year'] ?? null,
                'resource_type_id' => $data['resourceType'] ?? null,
                'version' => $data['version'] ?? null,
                'language_id' => $languageId,
                'publisher_id' => Publisher::getDefault()?->id,
            ];

            $isUpdate = ! empty($data['resourceId']);

            if ($isUpdate) {
                /** @var Resource $resource */
                $resource = Resource::query()
                    ->lockForUpdate()
                    ->findOrFail($data['resourceId']);

                // Track who updated the resource
                $attributes['updated_by_user_id'] = $userId;

                $resource->update($attributes);
            } else {
                // Track who created the resource
                $attributes['created_by_user_id'] = $userId;

                $resource = Resource::query()->create($attributes);
            }

            $this->storeTitles($resource, $data, $isUpdate);
            $this->syncLicenses($resource, $data);
            $this->storeCreators($resource, $data, $isUpdate);
            $this->storeContributors($resource, $data, $isUpdate);
            $this->storeMslLaboratories($resource, $data, $isUpdate);
            $this->storeDescriptions($resource, $data, $isUpdate);
            $this->storeDates($resource, $data, $isUpdate);
            $this->storeSubjects($resource, $data, $isUpdate);
            // Subject updates can happen without a dirty Resource model update,
            // and relation->delete() bypasses Subject model events. Schedule
            // one shared portal keyword/thesaurus cache invalidation after commit.
            $this->portalKeywordCacheInvalidationService->scheduleAfterCommit();
            $this->storeGeoLocations($resource, $data, $isUpdate);
            $this->storeRelatedIdentifiers($resource, $data, $isUpdate);
            $this->storeRelatedItems($resource, $data, $isUpdate);
            $this->storeFundingReferences($resource, $data, $isUpdate);
            $this->storeInstruments($resource, $data, $isUpdate);
            $this->syncDatacenters($resource, $data);

            return [
                $resource->load([
                    'titles',
                    'rights',
                    'creators',
                    'contributors',
                    'descriptions',
                    'dates',
                    'subjects',
                    'geoLocations',
                    'relatedIdentifiers',
                    'fundingReferences',
                    'datacenters',
                ]),
                $isUpdate,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepareDataForStorage(array $data): array
    {
        $relatedIdentifiers = $data['relatedIdentifiers'] ?? null;

        if (! is_array($relatedIdentifiers)) {
            $data['relatedIdentifiers'] = [];

            return $this->ensureAuthorContactPersonContributors($data);
        }

        $citationResolutionDeadline = microtime(true) + RelatedIdentifierCitationLabelService::DEFAULT_AGGREGATE_TIMEOUT_SECONDS;

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            if (! is_array($relatedIdentifier)) {
                continue;
            }

            $identifier = isset($relatedIdentifier['identifier'])
                ? trim((string) $relatedIdentifier['identifier'])
                : '';

            if ($identifier === '') {
                unset($relatedIdentifiers[$index]['citationLabel']);

                continue;
            }

            $relatedIdentifiers[$index]['identifier'] = $identifier;

            $citationLabel = isset($relatedIdentifier['citationLabel'])
                ? trim((string) $relatedIdentifier['citationLabel'])
                : '';

            if ($citationLabel !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = $citationLabel;

                continue;
            }

            $resolvedCitationLabel = $this->relatedIdentifierCitationLabelService->resolveBestEffort(
                $identifier,
                (string) ($relatedIdentifier['identifierType'] ?? ''),
                $citationResolutionDeadline,
            );

            if (is_string($resolvedCitationLabel) && trim($resolvedCitationLabel) !== '') {
                $relatedIdentifiers[$index]['citationLabel'] = trim($resolvedCitationLabel);

                continue;
            }

            unset($relatedIdentifiers[$index]['citationLabel']);
        }

        $data['relatedIdentifiers'] = $relatedIdentifiers;

        return $this->ensureAuthorContactPersonContributors($data);
    }

    /**
     * Expand the editor's author-level CP flag back into the DataCite-compatible
     * contributor representation required for storage and export.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ensureAuthorContactPersonContributors(array $data): array
    {
        $authors = $data['authors'] ?? [];

        if (! is_array($authors)) {
            return $data;
        }

        $contributors = $data['contributors'] ?? [];

        if (! is_array($contributors)) {
            $contributors = [];
        }

        foreach ($authors as $author) {
            if (! is_array($author) || ($author['type'] ?? 'person') !== 'person' || ! (bool) ($author['isContact'] ?? false)) {
                continue;
            }

            $matchingContributorIndex = $this->matchingPersonContributorIndex($contributors, $author);

            if ($matchingContributorIndex !== null) {
                /** @var array<string, mixed> $contributor */
                $contributor = $contributors[$matchingContributorIndex];
                $contributor['roles'] = $this->rolesWithContactPerson($contributor['roles'] ?? []);
                $contributor['email'] = $author['email'] ?? null;
                $contributor['website'] = $author['website'] ?? null;
                $contributors[$matchingContributorIndex] = $contributor;

                continue;
            }

            $contributors[] = $this->authorContactContributorData($author, count($contributors));
        }

        $data['contributors'] = array_values($contributors);

        return $data;
    }

    /**
     * @param  array<int, mixed>  $contributors
     * @param  array<string, mixed>  $author
     */
    private function matchingPersonContributorIndex(array $contributors, array $author): ?int
    {
        $authorKeys = $this->personDataIdentityKeys($author);

        if ($authorKeys === []) {
            return null;
        }

        foreach ($contributors as $index => $contributor) {
            if (! is_array($contributor) || ($contributor['type'] ?? 'person') !== 'person') {
                continue;
            }

            if (array_intersect($authorKeys, $this->personDataIdentityKeys($contributor)) !== []) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $personData
     * @return list<string>
     */
    private function personDataIdentityKeys(array $personData): array
    {
        $orcidIdentityKey = $this->personDataOrcidIdentityKey($personData);

        if ($orcidIdentityKey !== null) {
            return [$orcidIdentityKey];
        }

        $keys = [];
        $firstName = $this->normalisePersonIdentityPart($personData['firstName'] ?? null);
        $lastName = $this->normalisePersonIdentityPart($personData['lastName'] ?? null);

        if ($firstName !== null && $lastName !== null) {
            $keys[] = 'name:'.$lastName.'|'.$firstName;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $personData
     */
    private function personDataOrcidIdentityKey(array $personData): ?string
    {
        $orcid = $this->filledContactString($personData['orcid'] ?? null);

        if ($orcid === null) {
            return null;
        }

        $bareOrcid = OrcidNormalizer::extractBareId($orcid);

        if ($bareOrcid === '' || ! OrcidNormalizer::isValidFormat($bareOrcid)) {
            return null;
        }

        return 'orcid:'.strtolower($bareOrcid);
    }

    private function normalisePersonIdentityPart(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $normalised = mb_strtolower($value, 'UTF-8');
        $normalised = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalised) ?: $normalised;
        $normalised = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $normalised) ?? '';
        $normalised = preg_replace('/\s+/', ' ', $normalised) ?? '';

        return trim($normalised);
    }

    /**
     * @return list<string>
     */
    private function rolesWithContactPerson(mixed $roles): array
    {
        $roles = is_array($roles)
            ? array_values(array_filter(
                $roles,
                fn (mixed $role): bool => is_string($role) && trim($role) !== '',
            ))
            : [];

        if (! $this->hasContactPersonRole(['roles' => $roles])) {
            $roles[] = 'ContactPerson';
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param  array<string, mixed>  $author
     * @return array<string, mixed>
     */
    private function authorContactContributorData(array $author, int $position): array
    {
        return [
            'type' => 'person',
            'firstName' => $author['firstName'] ?? null,
            'lastName' => $author['lastName'] ?? null,
            'orcid' => $author['orcid'] ?? null,
            'roles' => ['ContactPerson'],
            'email' => $author['email'] ?? null,
            'website' => $author['website'] ?? null,
            'affiliations' => $author['affiliations'] ?? [],
            'position' => $position,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeTitles(Resource $resource, array $data, bool $isUpdate): void
    {
        /**
         * Map normalized (kebab-case) slugs to DB title type IDs.
         * Editor and legacy import payloads can provide values like "alternative-title",
         * while the database stores DataCite slugs like "AlternativeTitle".
         *
         * @var array<string, int> $titleTypeMap
         */
        $titleTypeMap = TitleType::query()
            ->get(['id', 'slug'])
            ->mapWithKeys(fn (TitleType $type): array => [Str::kebab($type->slug) => $type->id])
            ->all();

        // Also add mapping for empty string and 'main-title' to MainTitle ID
        // Note: 'MainTitle' in kebab-case becomes 'main-title'
        $mainTitleId = $titleTypeMap['main-title'] ?? $this->ensureTitleType('MainTitle', 'Main Title');
        $titleTypeMap['main-title'] = $mainTitleId;
        $titleTypeMap[''] = $mainTitleId;

        $resourceTitles = [];

        foreach ($data['titles'] as $index => $title) {
            $normalized = Str::kebab($title['titleType'] ?? '');

            $titleTypeId = $titleTypeMap[$normalized] ?? null;
            if ($titleTypeId === null) {
                // This should be prevented by StoreResourceRequest validation, but keep a safe failure mode.
                throw ValidationException::withMessages([
                    "titles.$index.titleType" => 'Unknown title type. Please select a valid title type.',
                ]);
            }

            $resourceTitles[] = [
                'value' => $title['title'],
                'title_type_id' => $titleTypeId,
                'language' => $title['language'] ?? null,
            ];
        }

        if ($isUpdate) {
            $resource->titles()->delete();
        }

        $resource->titles()->createMany($resourceTitles);
    }

    private function ensureTitleType(string $slug, string $name): int
    {
        return (int) TitleType::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'is_active' => true,
                'is_elmo_active' => true,
            ],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncLicenses(Resource $resource, array $data): void
    {
        $licenseIdentifiers = $this->licenseIdentifierList($data['licenses'] ?? []);
        $sourceResourceRightLinks = [];

        foreach ($this->customLicenseList($data['customLicenses'] ?? []) as $customLicense) {
            $right = $this->customRightCatalog->findOrCreate($customLicense['name'], $customLicense['uri']);
            $licenseIdentifiers[] = $right->identifier;

            if (isset($customLicense['sourceResourceRightId'])) {
                $sourceResourceRightLinks[$customLicense['sourceResourceRightId']] = (int) $right->id;
            }
        }

        $this->resourceRightsStorage->syncEditorRights(
            $resource,
            array_values(array_unique($licenseIdentifiers)),
            $this->rawRightsList($data['rawRights'] ?? []),
            $resource->language?->code,
            $sourceResourceRightLinks,
            array_key_exists('customLicenses', $data),
        );
    }

    /**
     * @return list<string>
     */
    private function licenseIdentifierList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $identifiers = [];

        foreach ($value as $identifier) {
            if (is_string($identifier)) {
                $identifiers[] = $identifier;
            }
        }

        return $identifiers;
    }

    /**
     * @return list<array{name: string, uri: string, sourceResourceRightId?: int}>
     */
    private function customLicenseList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $customLicenses = [];

        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $name = isset($entry['name']) && (is_string($entry['name']) || is_numeric($entry['name']))
                ? trim((string) $entry['name'])
                : '';
            $uri = isset($entry['uri']) && (is_string($entry['uri']) || is_numeric($entry['uri']))
                ? trim((string) $entry['uri'])
                : '';

            if ($name === '' && $uri === '') {
                continue;
            }

            $customLicense = [
                'name' => $name,
                'uri' => $uri,
            ];

            if (isset($entry['sourceResourceRightId']) && is_numeric($entry['sourceResourceRightId'])) {
                $customLicense['sourceResourceRightId'] = (int) $entry['sourceResourceRightId'];
            }

            $customLicenses[] = $customLicense;
        }

        return $customLicenses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rawRightsList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rawRights = [];

        foreach ($value as $statement) {
            if (! is_array($statement)) {
                continue;
            }

            $normalized = [];

            foreach ($statement as $key => $item) {
                if (is_string($key)) {
                    $normalized[$key] = $item;
                }
            }

            if ($normalized !== []) {
                $rawRights[] = $normalized;
            }
        }

        return $rawRights;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeCreators(Resource $resource, array $data, bool $isUpdate): void
    {
        $resource->creators()->delete();

        $authors = $data['authors'] ?? [];

        foreach ($authors as $author) {
            $position = isset($author['position']) && is_int($author['position'])
                ? $author['position']
                : 0;

            if (($author['type'] ?? 'person') === 'institution') {
                $resourceCreator = $this->storeInstitutionCreator($resource, $author, $position);
            } else {
                $resourceCreator = $this->storePersonCreator($resource, $author, $position);
            }

            $this->affiliationService->syncForCreator($resourceCreator, $author);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonCreator(Resource $resource, array $data, int $position): ResourceCreator
    {
        $person = $this->personService->findOrCreate($data);
        $isContact = (bool) ($data['isContact'] ?? false);
        $contactInfo = $this->validatedContactInfo($data);

        return ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => $position,
            'is_contact' => $isContact,
            'email' => $isContact ? $contactInfo['email'] : null,
            'website' => $isContact ? $contactInfo['website'] : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionCreator(Resource $resource, array $data, int $position): ResourceCreator
    {
        $institution = $this->institutionService->findOrCreate([
            'name' => $data['institutionName'],
            'identifier' => $data['rorId'] ?? null,
            'identifierScheme' => isset($data['rorId']) ? 'ROR' : null,
        ]);

        return ResourceCreator::query()->create([
            'resource_id' => $resource->id,
            'creatorable_id' => $institution->id,
            'creatorable_type' => Institution::class,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeContributors(Resource $resource, array $data, bool $isUpdate): void
    {
        // Delete old MSL labs if updating (before adding new ones)
        if ($isUpdate) {
            // Get all existing MSL labs (institutions with name_identifier_scheme = 'labid')
            // Use whereIn with subquery to avoid morph type issues
            $mslLabIds = Institution::where('name_identifier_scheme', 'labid')
                ->pluck('id');

            $mslLabs = ResourceContributor::query()
                ->where('resource_id', $resource->id)
                ->where('contributorable_type', Institution::class)
                ->whereIn('contributorable_id', $mslLabIds)
                ->get();

            // Properly cleanup relationships before deleting
            foreach ($mslLabs as $mslLab) {
                $mslLab->affiliations()->delete(); // Delete child affiliation records
                $mslLab->delete();               // Finally delete the ResourceContributor
            }
        }

        $contributors = $data['contributors'] ?? [];

        // Delete old contributors if updating
        if ($isUpdate) {
            $existingContributors = ResourceContributor::query()
                ->where('resource_id', $resource->id)
                ->get();

            foreach ($existingContributors as $contrib) {
                $contrib->affiliations()->delete();
                $contrib->delete();
            }
        }

        foreach ($contributors as $contributor) {
            $position = isset($contributor['position']) && is_int($contributor['position'])
                ? $contributor['position']
                : 0;

            if (($contributor['type'] ?? 'person') === 'institution') {
                $resourceContributor = $this->storeInstitutionContributor($resource, $contributor, $position);
            } else {
                $resourceContributor = $this->storePersonContributor($resource, $contributor, $position);
            }

            $this->syncContributorTypes($resourceContributor, $contributor);
            $this->affiliationService->syncForContributor($resourceContributor, $contributor);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storePersonContributor(Resource $resource, array $data, int $position): ResourceContributor
    {
        $person = $this->personService->findOrCreate($data);

        $hasContactPersonRole = $this->hasContactPersonRole($data);
        $contactInfo = $this->validatedContactInfo($data);

        return ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $person->id,
            'contributorable_type' => Person::class,
            'position' => $position,
            'email' => $hasContactPersonRole ? $contactInfo['email'] : null,
            'website' => $hasContactPersonRole ? $contactInfo['website'] : null,
        ]);
    }

    /**
     * Check if the contributor data includes the "Contact Person" role.
     *
     * Uses cached name/slug values from the database to stay consistent
     * with {@see syncContributorTypes()} without issuing per-contributor queries.
     *
     * @param  array<string, mixed>  $data
     */
    private function hasContactPersonRole(array $data): bool
    {
        $roles = $data['roles'] ?? [];

        if (! is_array($roles)) {
            return false;
        }

        $validRoles = array_values(array_filter(
            $roles,
            fn (mixed $role): bool => is_string($role) && trim($role) !== '',
        ));

        if ($validRoles === []) {
            return false;
        }

        if ($this->contactPersonNames === null) {
            $type = ContributorType::where('slug', 'ContactPerson')->first(['name', 'slug']);
            $this->contactPersonNames = $type ? [$type->name, $type->slug] : [];
        }

        if ($this->contactPersonNames === []) {
            return false;
        }

        return array_any(
            $validRoles,
            fn (string $role): bool => in_array(trim($role), $this->contactPersonNames, true),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeInstitutionContributor(Resource $resource, array $data, int $position): ResourceContributor
    {
        $institution = $this->institutionService->findOrCreateWithIdentifier(
            $data['institutionName'],
            $data['identifier'] ?? null,
            $data['identifierType'] ?? null
        );

        return ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $institution->id,
            'contributorable_type' => Institution::class,
            'position' => $position,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{email: string|null, website: string|null}
     */
    private function validatedContactInfo(array $data): array
    {
        return [
            'email' => $this->validatedContactEmail($data['email'] ?? null),
            'website' => $this->validatedContactWebsite($data['website'] ?? null),
        ];
    }

    private function validatedContactEmail(mixed $value): ?string
    {
        $email = $this->filledContactString($value);

        if ($email === null) {
            return null;
        }

        if (
            mb_strlen($email) > self::CONTACT_FIELD_MAX_LENGTH
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            Log::debug('Skipping invalid contact email while storing resource');

            return null;
        }

        return $email;
    }

    private function validatedContactWebsite(mixed $value): ?string
    {
        $website = $this->filledContactString($value);

        if ($website === null) {
            return null;
        }

        $uri = UriHelper::parse($website);
        $scheme = strtolower($uri?->getScheme() ?? '');
        $host = trim($uri?->getHost() ?? '');

        if (
            mb_strlen($website) > self::CONTACT_FIELD_MAX_LENGTH
            || ! in_array($scheme, ['http', 'https'], true)
            || $host === ''
        ) {
            Log::debug('Skipping invalid contact website while storing resource');

            return null;
        }

        return $website;
    }

    private function filledContactString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * Sync all contributor types (roles) for a resource contributor via the pivot table.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncContributorTypes(ResourceContributor $resourceContributor, array $data): void
    {
        $roles = $data['roles'] ?? [];

        if (! is_array($roles) || $roles === []) {
            // No roles provided — default to "Other"
            $otherType = ContributorType::where('slug', 'Other')->first();
            if ($otherType) {
                $resourceContributor->contributorTypes()->sync([$otherType->id]);
            }

            return;
        }

        // Filter to valid, non-empty role strings
        $validRoles = array_values(array_filter(
            $roles,
            fn (mixed $role): bool => is_string($role) && trim($role) !== '',
        ));

        if ($validRoles === []) {
            $otherType = ContributorType::where('slug', 'Other')->first();
            if ($otherType) {
                $resourceContributor->contributorTypes()->sync([$otherType->id]);
            }

            return;
        }

        // Resolve all needed ContributorType IDs in a single query
        $typeIds = ContributorType::where(function ($query) use ($validRoles): void {
            $query->whereIn('name', $validRoles)
                ->orWhereIn('slug', $validRoles);
        })->pluck('id')->all();

        if ($typeIds !== []) {
            $resourceContributor->contributorTypes()->sync($typeIds);
        } else {
            // Fallback to "Other" if no valid roles were found
            $otherType = ContributorType::where('slug', 'Other')->first();
            if ($otherType) {
                $resourceContributor->contributorTypes()->sync([$otherType->id]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeMslLaboratories(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save MSL Laboratories as contributors with HostingInstitution type
        $mslLaboratories = $data['mslLaboratories'] ?? [];

        foreach ($mslLaboratories as $lab) {
            $position = (int) ($lab['position'] ?? 0);

            $resourceContributor = $this->storeMslLaboratory($resource, $lab, $position);
            $this->syncMslLaboratoryAffiliation($resourceContributor, $lab);
        }
    }

    /**
     * Store an MSL Laboratory as Institution contributor.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeMslLaboratory(Resource $resource, array $data, int $position): ResourceContributor
    {
        $institution = $this->institutionService->findOrCreateMslLaboratory($data);

        // Create ResourceContributor link
        $resourceContributor = ResourceContributor::query()->create([
            'resource_id' => $resource->id,
            'contributorable_id' => $institution->id,
            'contributorable_type' => Institution::class,
            'position' => $position,
        ]);

        // Attach HostingInstitution contributor type via pivot table
        $contributorType = ContributorType::where('slug', 'HostingInstitution')->first();
        if ($contributorType) {
            $resourceContributor->contributorTypes()->sync([$contributorType->id]);
        }

        return $resourceContributor;
    }

    /**
     * Sync the affiliation (host institution) for an MSL Laboratory.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncMslLaboratoryAffiliation(ResourceContributor $resourceContributor, array $data): void
    {
        $affiliationName = $data['affiliation_name'] ?? '';
        $affiliationRor = $data['affiliation_ror'] ?? null;

        if (empty(trim($affiliationName))) {
            return;
        }

        // Canonicalize ROR identifier to ensure consistent HTTPS + lowercase format
        $canonicalRor = is_string($affiliationRor) && $affiliationRor !== ''
            ? $this->rorLookupService->canonicalise($affiliationRor)
            : null;

        $resourceContributor->affiliations()->create([
            'name' => trim($affiliationName),
            'identifier' => $canonicalRor,
            'identifier_scheme' => $canonicalRor ? 'ROR' : null,
            'scheme_uri' => $canonicalRor ? 'https://ror.org/' : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeDescriptions(Resource $resource, array $data, bool $isUpdate): void
    {
        if ($isUpdate) {
            $resource->descriptions()->delete();
        }

        // Pre-fetch description type IDs
        /** @var array<string, int> $descriptionTypeLookup */
        $descriptionTypeLookup = DescriptionType::query()
            ->get(['id', 'slug'])
            ->mapWithKeys(fn (DescriptionType $type): array => [
                // Use kebab-case slug as key to match StoreResourceRequest normalization
                Str::kebab($type->slug) => $type->id,
            ])
            ->all();

        $descriptions = $data['descriptions'] ?? [];

        foreach ($descriptions as $description) {
            $rawType = (string) ($description['descriptionType'] ?? '');
            $displayType = $rawType !== '' ? $rawType : 'empty';
            $descTypeKey = Str::kebab($rawType);
            $descTypeId = $descriptionTypeLookup[$descTypeKey] ?? null;

            if ($descTypeId === null) {
                // Throw validation exception for unknown description type to prevent silent data loss.
                // This matches the date type handling behavior for consistency.
                Log::warning('Unknown description type slug: '.$displayType);

                throw ValidationException::withMessages([
                    'descriptions' => ["Unknown description type: {$displayType}. Please select a valid description type."],
                ]);
            }

            $formattedDescription = $this->descriptionFormattingService->formatForStorage((string) ($description['description'] ?? ''));

            if ($formattedDescription['plainText'] === '') {
                throw ValidationException::withMessages([
                    'descriptions' => ["Description type {$displayType} does not contain any content after trimming and sanitization."],
                ]);
            }

            $resource->descriptions()->create([
                'description_type_id' => $descTypeId,
                'value' => $formattedDescription['plainText'],
                'landing_page_html' => $formattedDescription['landingPageHtml'],
                'language' => $description['language'] ?? null,
            ]);
        }
    }

    public function ensureSystemDate(Resource $resource, string $dateTypeSlug, ?string $dateValue = null): void
    {
        $dateTypeKey = Str::kebab($dateTypeSlug);
        $systemDateTypes = [
            'accepted' => ['name' => 'Accepted', 'slug' => 'Accepted'],
            'issued' => ['name' => 'Issued', 'slug' => 'Issued'],
            'updated' => ['name' => 'Updated', 'slug' => 'Updated'],
        ];

        if (! array_key_exists($dateTypeKey, $systemDateTypes)) {
            throw new \InvalidArgumentException('Only Accepted, Issued, and Updated can be written as system dates.');
        }

        $dateType = DateType::query()
            ->whereRaw('LOWER(slug) = ?', [$dateTypeKey])
            ->first();

        if (! $dateType instanceof DateType) {
            $dateType = DateType::query()->create([
                'name' => $systemDateTypes[$dateTypeKey]['name'],
                'slug' => $systemDateTypes[$dateTypeKey]['slug'],
                'is_active' => true,
            ]);
        }

        if ($resource->dates()->where('date_type_id', $dateType->id)->exists()) {
            return;
        }

        $resource->dates()->create([
            'date_type_id' => $dateType->id,
            'date_value' => $dateValue ?? now()->format('Y-m-d'),
            'start_date' => null,
            'end_date' => null,
            'date_information' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeDates(Resource $resource, array $data, bool $isUpdate): void
    {
        /** @var array<string, int> $dateTypeLookup */
        $dateTypeLookup = DateType::query()
            ->get(['id', 'slug'])
            ->mapWithKeys(fn (DateType $type): array => [Str::kebab($type->slug) => $type->id])
            ->all();

        $updatedDateTypeId = $dateTypeLookup['updated'] ?? null;
        $dates = isset($data['dates']) && is_array($data['dates']) ? $data['dates'] : [];

        if ($isUpdate) {
            $submittedDateTypes = collect($dates)
                ->filter(fn (mixed $date): bool => is_array($date))
                ->map(fn (array $date): string => Str::kebab((string) ($date['dateType'] ?? '')))
                ->filter(fn (string $dateType): bool => $dateType !== '')
                ->unique()
                ->values()
                ->all();

            $preservedDateTypeKeys = ['accepted', 'issued', 'coverage'];
            if (! in_array('created', $submittedDateTypes, true)) {
                $preservedDateTypeKeys[] = 'created';
            }

            $preservedDateTypeIds = array_values(array_filter(
                array_map(fn (string $dateType): ?int => $dateTypeLookup[$dateType] ?? null, $preservedDateTypeKeys),
                fn (?int $dateTypeId): bool => $dateTypeId !== null,
            ));

            if ($preservedDateTypeIds !== []) {
                $resource->dates()
                    ->whereNotIn('date_type_id', $preservedDateTypeIds)
                    ->delete();
            } else {
                $resource->dates()->delete();
            }
        }

        foreach ($dates as $index => $date) {
            if (! is_array($date)) {
                continue;
            }

            $dateTypeKey = Str::kebab((string) ($date['dateType'] ?? ''));

            if (in_array($dateTypeKey, ['accepted', 'issued', 'updated', 'coverage'], true)) {
                continue;
            }

            $dateTypeId = $dateTypeLookup[$dateTypeKey] ?? null;

            if ($dateTypeId === null) {
                $submittedDateType = (string) ($date['dateType'] ?? '');
                Log::warning('Unknown date type slug: '.$submittedDateType);

                throw ValidationException::withMessages([
                    'dates' => ["Unknown date type: {$submittedDateType}. Please select a valid date type."],
                ]);
            }

            $startDate = isset($date['startDate']) ? trim((string) $date['startDate']) : null;
            $startDate = $startDate !== '' ? $startDate : null;
            $endDate = isset($date['endDate']) ? trim((string) $date['endDate']) : null;
            $endDate = $endDate !== '' ? $endDate : null;
            $mode = isset($date['dateMode']) ? Str::kebab(trim((string) $date['dateMode'])) : null;
            $mode = $mode !== '' ? $mode : null;
            $supportsPeriod = in_array($dateTypeKey, ['created', 'collected', 'valid', 'other'], true);

            if ($mode !== null && ! in_array($mode, ['single', 'range'], true)) {
                throw ValidationException::withMessages([
                    "dates.$index.dateMode" => ['Date mode must be single or range.'],
                ]);
            }

            if ($mode === 'single' && $endDate !== null) {
                throw ValidationException::withMessages([
                    "dates.$index.endDate" => ['Single-date mode must not include an end date.'],
                ]);
            }

            if ($mode === 'range') {
                $messages = [];

                if (! $supportsPeriod) {
                    $messages["dates.$index.dateMode"] = ['Only Created, Collected, Valid, and Other dates can be stored as periods.'];
                }

                if ($startDate === null) {
                    $messages["dates.$index.startDate"] = ['Period mode requires a start date.'];
                }

                if ($endDate === null) {
                    $messages["dates.$index.endDate"] = ['Period mode requires an end date.'];
                }

                if ($messages !== []) {
                    throw ValidationException::withMessages($messages);
                }
            }

            if ($mode === null && $endDate !== null) {
                if ($startDate === null) {
                    throw ValidationException::withMessages([
                        "dates.$index.startDate" => ['Dates with an end date require a start date.'],
                    ]);
                }

                if (! $supportsPeriod) {
                    throw ValidationException::withMessages([
                        "dates.$index.endDate" => ['Only Created, Collected, Valid, and Other dates can include an end date.'],
                    ]);
                }
            }

            if ($startDate === null && $endDate === null) {
                continue;
            }

            $hasRange = ($mode === 'range' || ($mode === null && $endDate !== null))
                && $startDate !== null
                && $endDate !== null;

            $resource->dates()->create([
                'date_type_id' => $dateTypeId,
                'date_value' => $hasRange ? null : $startDate,
                'start_date' => $hasRange ? $startDate : null,
                'end_date' => $hasRange ? $endDate : null,
                'date_information' => $this->normalizeNullableString($date['dateInformation'] ?? null),
            ]);
        }

        if ($isUpdate && $updatedDateTypeId !== null) {
            $resource->dates()->create([
                'date_type_id' => $updatedDateTypeId,
                'date_value' => now()->format('Y-m-d'),
                'start_date' => null,
                'end_date' => null,
                'date_information' => null,
            ]);
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeSubjects(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save subjects (free keywords and controlled keywords combined)
        if ($isUpdate) {
            $resource->subjects()->delete();
        }

        $freeKeywords = $data['freeKeywords'] ?? [];

        foreach ($freeKeywords as $keyword) {
            // Only save non-empty keywords
            if (! empty(trim($keyword))) {
                $resource->subjects()->create([
                    'value' => trim($keyword),
                    'subject_scheme' => null,
                    'scheme_uri' => null,
                    'value_uri' => null,
                    'classification_code' => null,
                ]);
            }
        }

        $controlledKeywords = $data['gcmdKeywords'] ?? [];

        // Prepare controlled keywords for bulk creation
        $controlledKeywordsData = [];
        foreach ($controlledKeywords as $keyword) {
            // Validate required fields (scheme is now the discriminator instead of vocabularyType)
            if (empty($keyword['text']) || empty($keyword['scheme'])) {
                continue;
            }

            $keywordId = trim((string) ($keyword['id'] ?? ''));
            $keywordText = trim((string) $keyword['text']);
            $keywordText = SubjectBreadcrumbPath::normalize($keywordText) ?? $keywordText;
            $keywordScheme = trim((string) $keyword['scheme']);
            $keywordSchemeUri = is_string($keyword['schemeURI'] ?? null) ? trim((string) $keyword['schemeURI']) : null;
            $keywordPath = is_string($keyword['path'] ?? null) ? $keyword['path'] : null;

            if ($keywordId === '' || $keywordSchemeUri === null || $keywordSchemeUri === '') {
                $resolvedKeyword = $this->subjectBreadcrumbPathResolver->resolveKeywordFromPath(
                    $keywordScheme,
                    $keywordPath ?? $keywordText,
                );

                if ($resolvedKeyword !== null) {
                    $keywordId = $keywordId !== '' ? $keywordId : $resolvedKeyword['id'];
                    $keywordText = $keywordText !== '' ? $keywordText : $resolvedKeyword['text'];
                    $keywordScheme = $resolvedKeyword['scheme'];
                    $keywordSchemeUri = $keywordSchemeUri !== null && $keywordSchemeUri !== ''
                        ? $keywordSchemeUri
                        : $resolvedKeyword['schemeURI'];
                    $keywordPath = $resolvedKeyword['path'];
                }
            }

            if ($keywordId === '' || $keywordText === '' || $keywordScheme === '') {
                continue;
            }

            $keywordSchemeUri = $keywordSchemeUri !== null && $keywordSchemeUri !== ''
                ? $keywordSchemeUri
                : $this->subjectBreadcrumbPathResolver->resolveSchemeUri($keywordScheme);

            $rawCode = array_key_exists('classificationCode', $keyword) ? trim((string) $keyword['classificationCode']) : '';
            $classificationCode = $rawCode !== '' ? $rawCode : null;
            $valueUri = filter_var($keywordId, FILTER_VALIDATE_URL) ? $keywordId : null;

            $controlledKeywordsData[] = [
                'value' => $keywordText,
                'subject_scheme' => $keywordScheme,
                'scheme_uri' => $keywordSchemeUri,
                'value_uri' => $valueUri,
                'classification_code' => $classificationCode,
                'breadcrumb_path' => SubjectBreadcrumbPath::normalize($keywordPath),
            ];
        }

        // Bulk create controlled keywords using Eloquent (handles timestamps automatically)
        if (! empty($controlledKeywordsData)) {
            $resource->subjects()->createMany($controlledKeywordsData);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeGeoLocations(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save geo locations (spatial and temporal coverages)
        if ($isUpdate) {
            $resource->geoLocations()->delete();
        }

        $coverages = $data['spatialTemporalCoverages'] ?? [];

        foreach ($coverages as $coverage) {
            $type = $coverage['type'] ?? 'point';

            // Only save coverage if it has at least one meaningful field.
            // Helper closure to check if a coordinate value is provided.
            // Accepts: numeric 0, string "0", floats, integers.
            // Rejects: null, empty string, non-numeric values.
            $isCoordinateProvided = static function (mixed $value): bool {
                if ($value === null || $value === '') {
                    return false;
                }

                // Accept any numeric value (including 0)
                return is_numeric($value);
            };

            $hasData = $isCoordinateProvided($coverage['latMin'] ?? null)
                || $isCoordinateProvided($coverage['lonMin'] ?? null)
                || ! empty($coverage['polygonPoints'])
                || ! empty($coverage['description']);

            if ($hasData) {
                $geoLocationData = [
                    'place' => $coverage['description'] ?? null,
                ];

                // For polygon type, store polygon points as JSON (geo_locations.polygon_points)
                if ($type === 'polygon' && ! empty($coverage['polygonPoints']) && is_array($coverage['polygonPoints'])) {
                    $invalidPoints = [];

                    // Filter and transform polygon points, tracking rejected points for feedback.
                    // Valid ranges: latitude -90 to 90, longitude -180 to 180
                    $validPoints = array_filter(
                        $coverage['polygonPoints'],
                        static function (mixed $point, int $index) use (&$invalidPoints): bool {
                            if (! is_array($point)) {
                                $invalidPoints[] = 'Point '.($index + 1).': not a valid coordinate pair';

                                return false;
                            }
                            $lon = $point['longitude'] ?? $point['lon'] ?? null;
                            $lat = $point['latitude'] ?? $point['lat'] ?? null;

                            // Must have both values present.
                            // Use is_numeric() to correctly accept 0 (Equator/Prime Meridian).
                            if (! is_numeric($lon) || ! is_numeric($lat)) {
                                $invalidPoints[] = 'Point '.($index + 1).': missing or non-numeric coordinates';

                                return false;
                            }

                            // Validate coordinate ranges per WGS84 specification.
                            // Normalize to float early so error messages show sanitized values,
                            // not raw user input (security best practice).
                            $lonFloat = (float) $lon;
                            $latFloat = (float) $lat;

                            if ($latFloat < -90.0 || $latFloat > 90.0 || $lonFloat < -180.0 || $lonFloat > 180.0) {
                                $invalidPoints[] = sprintf(
                                    'Point %d: coordinates out of range (lat: %.6f, lon: %.6f)',
                                    $index + 1,
                                    $latFloat,
                                    $lonFloat
                                );

                                return false;
                            }

                            return true;
                        },
                        ARRAY_FILTER_USE_BOTH
                    );

                    // A valid polygon requires at least 3 points to form a closed shape.
                    // Throw validation error if we don't have enough valid points to prevent
                    // silent data loss where a polygon "disappears" without explanation.
                    // Note: GeoJSON/DataCite polygon semantics auto-close the shape (first point
                    // implicitly connects to last point), so explicit closure is not required.
                    if (count($validPoints) < 3) {
                        $message = 'Polygon requires at least 3 valid points, but only '.count($validPoints).' valid point(s) found.';
                        if (! empty($invalidPoints)) {
                            $message .= ' Rejected points: '.implode('; ', array_slice($invalidPoints, 0, 5));
                            if (count($invalidPoints) > 5) {
                                $message .= ' and '.(count($invalidPoints) - 5).' more.';
                            }
                        }

                        throw ValidationException::withMessages([
                            'coverages' => [$message],
                        ]);
                    }

                    $geoLocationData['geo_type'] = 'polygon';
                    $geoLocationData['polygon_points'] = array_values(array_map(
                        static fn (array $point): array => [
                            'longitude' => (float) ($point['longitude'] ?? $point['lon']),
                            'latitude' => (float) ($point['latitude'] ?? $point['lat']),
                        ],
                        $validPoints
                    ));

                    $resource->geoLocations()->create($geoLocationData);
                } elseif ($type === 'line' && ! empty($coverage['polygonPoints']) && is_array($coverage['polygonPoints'])) {
                    // Line type: stored in polygon_points with geo_type = 'line'
                    $invalidPoints = [];

                    $validPoints = array_filter(
                        $coverage['polygonPoints'],
                        static function (mixed $point, int $index) use (&$invalidPoints): bool {
                            if (! is_array($point)) {
                                $invalidPoints[] = 'Point '.($index + 1).': not a valid coordinate pair';

                                return false;
                            }
                            $lon = $point['longitude'] ?? $point['lon'] ?? null;
                            $lat = $point['latitude'] ?? $point['lat'] ?? null;

                            if (! is_numeric($lon) || ! is_numeric($lat)) {
                                $invalidPoints[] = 'Point '.($index + 1).': missing or non-numeric coordinates';

                                return false;
                            }

                            $lonFloat = (float) $lon;
                            $latFloat = (float) $lat;

                            if ($latFloat < -90.0 || $latFloat > 90.0 || $lonFloat < -180.0 || $lonFloat > 180.0) {
                                $invalidPoints[] = sprintf(
                                    'Point %d: coordinates out of range (lat: %.6f, lon: %.6f)',
                                    $index + 1,
                                    $latFloat,
                                    $lonFloat
                                );

                                return false;
                            }

                            return true;
                        },
                        ARRAY_FILTER_USE_BOTH
                    );

                    if (count($validPoints) < 2) {
                        $message = 'Line requires at least 2 valid points, but only '.count($validPoints).' valid point(s) found.';
                        if (! empty($invalidPoints)) {
                            $message .= ' Rejected points: '.implode('; ', array_slice($invalidPoints, 0, 5));
                            if (count($invalidPoints) > 5) {
                                $message .= ' and '.(count($invalidPoints) - 5).' more.';
                            }
                        }

                        throw ValidationException::withMessages([
                            'coverages' => [$message],
                        ]);
                    }

                    $geoLocationData['geo_type'] = 'line';
                    $geoLocationData['polygon_points'] = array_values(array_map(
                        static fn (array $point): array => [
                            'longitude' => (float) ($point['longitude'] ?? $point['lon']),
                            'latitude' => (float) ($point['latitude'] ?? $point['lat']),
                        ],
                        $validPoints
                    ));

                    $resource->geoLocations()->create($geoLocationData);
                } elseif ($type === 'point') {
                    // Point type
                    $geoLocationData['geo_type'] = 'point';
                    $geoLocationData['point_longitude'] = $coverage['lonMin'] ?? null;
                    $geoLocationData['point_latitude'] = $coverage['latMin'] ?? null;
                    $resource->geoLocations()->create($geoLocationData);
                } else {
                    // Box type
                    $geoLocationData['geo_type'] = 'box';
                    $geoLocationData['west_bound_longitude'] = $coverage['lonMin'] ?? null;
                    $geoLocationData['east_bound_longitude'] = $coverage['lonMax'] ?? null;
                    $geoLocationData['south_bound_latitude'] = $coverage['latMin'] ?? null;
                    $geoLocationData['north_bound_latitude'] = $coverage['latMax'] ?? null;
                    $resource->geoLocations()->create($geoLocationData);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeRelatedIdentifiers(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save related identifiers
        if ($isUpdate) {
            $resource->relatedIdentifiers()->delete();
        }

        // Pre-fetch related identifier type and relation type IDs
        /** @var array<string, int> $relatedIdTypeLookup */
        $relatedIdTypeLookup = IdentifierType::pluck('id', 'slug')->all();
        /** @var array<string, int> $relationTypeLookup */
        $relationTypeLookup = RelationType::pluck('id', 'slug')->all();

        $relatedIdentifiers = $data['relatedIdentifiers'] ?? [];

        foreach ($relatedIdentifiers as $index => $relatedIdentifier) {
            // Only save if identifier is not empty
            if (! empty(trim($relatedIdentifier['identifier']))) {
                $citationLabel = isset($relatedIdentifier['citationLabel']) && trim($relatedIdentifier['citationLabel']) !== ''
                    ? trim($relatedIdentifier['citationLabel'])
                    : null;

                $resource->relatedIdentifiers()->create([
                    'identifier' => trim($relatedIdentifier['identifier']),
                    'identifier_type_id' => $relatedIdTypeLookup[$relatedIdentifier['identifierType']] ?? null,
                    'relation_type_id' => $relationTypeLookup[$relatedIdentifier['relationType']] ?? null,
                    'relation_type_information' => isset($relatedIdentifier['relationTypeInformation']) && trim($relatedIdentifier['relationTypeInformation']) !== '' ? trim($relatedIdentifier['relationTypeInformation']) : null,
                    'citation_label' => $citationLabel,
                    'position' => $index,
                ]);
            }
        }
    }

    /**
     * Save inline <relatedItem> metadata (DataCite 4.7 Related Item Manager).
     *
     * Resolves `relation_type_slug` → id and delegates persistence of the
     * full aggregate (item + titles + creators + contributors + affiliations)
     * to {@see RelatedItemStorageService}. Uses delete-and-recreate on update
     * for consistency with other relations in this service.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeRelatedItems(Resource $resource, array $data, bool $isUpdate): void
    {
        // Citations are persisted via the dedicated /resources/{id}/related-items
        // REST endpoints (Related Item Manager). Only touch them here when the caller
        // explicitly includes a `relatedItems` payload (e.g., XML import or full
        // resource replace). Otherwise leave existing related items untouched so
        // a regular editor save does not wipe them.
        if (! array_key_exists('relatedItems', $data)) {
            return;
        }

        if ($isUpdate) {
            foreach ($resource->relatedItems()->get() as $existing) {
                $this->relatedItemStorage->delete($existing);
            }
        }

        $items = $data['relatedItems'] ?? [];
        if (! is_array($items) || $items === []) {
            return;
        }

        /** @var array<string, int> $relationTypeLookup */
        $relationTypeLookup = RelationType::query()->pluck('id', 'slug')->all();

        foreach (array_values($items) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $slug = $item['relation_type_slug'] ?? null;
            if (! is_string($slug) || ! isset($relationTypeLookup[$slug])) {
                continue;
            }

            $payload = $item;
            $payload['relation_type_id'] = $relationTypeLookup[$slug];
            $payload['position'] = $item['position'] ?? $index;
            unset($payload['relation_type_slug']);

            $this->relatedItemStorage->create($resource, $payload);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeFundingReferences(Resource $resource, array $data, bool $isUpdate): void
    {
        // Save funding references
        if ($isUpdate) {
            $resource->fundingReferences()->delete();
        }

        // Pre-fetch funder identifier type IDs (consistent with storeRelatedIdentifiers)
        /** @var array<string, int> $funderTypeLookup */
        $funderTypeLookup = FunderIdentifierType::pluck('id', 'slug')->all();

        $fundingReferences = $data['fundingReferences'] ?? [];

        foreach ($fundingReferences as $index => $fundingReference) {
            // Only save if funder name is not empty (required field)
            if (! empty(trim($fundingReference['funderName']))) {
                $typeName = ! empty($fundingReference['funderIdentifierType']) ? $fundingReference['funderIdentifierType'] : null;

                $resource->fundingReferences()->create([
                    'funder_name' => trim($fundingReference['funderName']),
                    'funder_identifier' => ! empty($fundingReference['funderIdentifier']) ? trim($fundingReference['funderIdentifier']) : null,
                    'funder_identifier_type_id' => $typeName !== null ? ($funderTypeLookup[$typeName] ?? null) : null,
                    'scheme_uri' => $this->getFunderIdentifierSchemeUri($typeName),
                    'award_number' => ! empty($fundingReference['awardNumber']) ? trim($fundingReference['awardNumber']) : null,
                    'award_uri' => ! empty($fundingReference['awardUri']) ? trim($fundingReference['awardUri']) : null,
                    'award_title' => ! empty($fundingReference['awardTitle']) ? trim($fundingReference['awardTitle']) : null,
                ]);
            }
        }
    }

    /**
     * Get the scheme URI for a funder identifier type.
     */
    private function getFunderIdentifierSchemeUri(?string $typeName): ?string
    {
        if (empty($typeName)) {
            return null;
        }

        return match ($typeName) {
            'ROR' => 'https://ror.org/',
            'Crossref Funder ID' => 'https://doi.org/10.13039/',
            'ISNI' => 'https://isni.org/',
            'GRID' => 'https://www.grid.ac/',
            default => null,
        };
    }

    /**
     * Store instruments (PID4INST) for a resource.
     *
     * Uses the delete-and-recreate pattern consistent with other relations.
     *
     * @param  array<string, mixed>  $data
     */
    private function storeInstruments(Resource $resource, array $data, bool $isUpdate): void
    {
        if ($isUpdate) {
            $resource->instruments()->delete();
        }

        $instruments = $data['instruments'] ?? [];

        foreach ($instruments as $index => $instrument) {
            if (! is_array($instrument)) {
                continue;
            }

            $pid = isset($instrument['pid']) && is_string($instrument['pid']) ? trim($instrument['pid']) : '';
            $name = isset($instrument['name']) && is_string($instrument['name']) ? trim($instrument['name']) : '';

            if ($pid === '' || $name === '') {
                continue;
            }

            $pidType = isset($instrument['pidType']) && is_string($instrument['pidType'])
                ? $instrument['pidType']
                : 'Handle';

            $resource->instruments()->create([
                'instrument_pid' => mb_substr($pid, 0, 512),
                'instrument_pid_type' => mb_substr($pidType, 0, 50),
                'instrument_name' => mb_substr($name, 0, 1024),
                'position' => $index,
            ]);
        }
    }

    /**
     * Sync datacenters for a resource.
     *
     * Uses sync() instead of the delete-and-recreate pattern because
     * datacenters are a simple many-to-many relationship without
     * additional pivot data or ordering.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncDatacenters(Resource $resource, array $data): void
    {
        if (! array_key_exists('datacenters', $data)) {
            return;
        }

        $datacenterIds = $data['datacenters'];

        if (! is_array($datacenterIds)) {
            return;
        }

        $changes = $resource->datacenters()->sync(array_map(intval(...), $datacenterIds));

        // Pivot changes don't trigger Eloquent's updated event, so touch
        // the resource to fire the observer and invalidate portal caches.
        if (array_filter($changes)) {
            $resource->touch();
        }
    }
}
