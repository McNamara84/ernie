<?php

declare(strict_types=1);

namespace App\Services\Editor;

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\Datacenter;
use App\Models\FundingReference;
use App\Models\IdentifierType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\RelatedIdentifier;
use App\Models\RelationType;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\Right;
use App\Models\Setting;
use App\Services\Rights\CustomRightCatalogService;
use App\Support\GemetVocabularyParser;
use App\Support\OrcidNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Service to transform Resource models into editor-compatible data format.
 *
 * This service extracts transformation logic from the editor route,
 * providing a clean separation of concerns.
 */
class EditorDataTransformer
{
    /**
     * Map description type slugs to frontend format.
     *
     * @var array<string, string>
     */
    private const DESCRIPTION_TYPE_MAP = [
        'abstract' => 'Abstract',
        'methods' => 'Methods',
        'series-information' => 'SeriesInformation',
        'table-of-contents' => 'TableOfContents',
        'technical-info' => 'TechnicalInfo',
        'other' => 'Other',
    ];

    /**
     * Transform a Resource model into editor-compatible data.
     *
     * @return array<string, mixed>
     */
    public function transformResource(Resource $resource): array
    {
        // Cache creators transform to avoid duplicate computation
        $creators = $this->transformCreators($resource);

        return [
            'doi' => $resource->doi ?? '',
            'year' => (string) $resource->publication_year,
            'version' => $resource->version ?? '',
            'language' => $resource->language->code ?? '',
            'resourceType' => (string) $resource->resource_type_id,
            'resourceId' => (string) $resource->id,
            'titles' => $this->transformTitles($resource),
            'initialLicenses' => $this->transformLicenses($resource),
            'initialRawRights' => $this->transformRawRights($resource),
            'authors' => $creators['authors'],
            'contributors' => $creators['contributors'],
            'descriptions' => $this->transformDescriptions($resource),
            'dates' => $this->transformDates($resource),
            'gcmdKeywords' => $this->transformGcmdKeywords($resource),
            'freeKeywords' => $this->transformFreeKeywords($resource),
            'gemetKeywords' => $this->transformGemetKeywords($resource),
            'coverages' => $this->transformCoverages($resource),
            'relatedWorks' => $this->transformRelatedIdentifiers($resource),
            'fundingReferences' => $this->transformFundingReferences($resource),
            'mslLaboratories' => $this->transformMslLaboratories($resource),
            'instruments' => $this->transformInstruments($resource),
            'initialDatacenters' => $resource->datacenters->pluck('id')->all(),
            'landingPage' => $resource->landingPage ? [
                'id' => $resource->landingPage->id,
                'is_published' => $resource->landingPage->is_published,
                'status' => $resource->landingPage->status,
                'public_url' => $resource->landingPage->public_url,
                'preview_url' => $resource->landingPage->preview_url,
                'external_url' => $resource->landingPage->external_url,
            ] : null,
        ];
    }

    /**
     * Get common editor props (settings, API keys).
     *
     * @return array<string, mixed>
     */
    public function getCommonProps(): array
    {
        return [
            'maxTitles' => (int) Setting::getValue('max_titles', Setting::DEFAULT_LIMIT),
            'maxLicenses' => (int) Setting::getValue('max_licenses', Setting::DEFAULT_LIMIT),
            'googleMapsApiKey' => config('services.google_maps.api_key'),
            'activeRelationTypes' => RelationType::active()->orderByName()->pluck('slug')->values()->all(),
            'activeIdentifierTypes' => IdentifierType::active()->orderByName()->pluck('slug')->values()->all(),
            'availableDatacenters' => Datacenter::orderBy('name')->get(['id', 'name'])->toArray(),
        ];
    }

    /**
     * Transform resource titles to frontend format.
     *
     * @return array<int, array{title: string, titleType: string, language: string|null}>
     */
    public function transformTitles(Resource $resource): array
    {
        return $resource->titles->map(function ($title): array {
            // Frontend uses kebab-case slugs; main title is represented as 'main-title'
            // Use null-safe operator for legacy data where titleType may be null
            $titleType = $title->isMainTitle()
                ? 'main-title'
                // @phpstan-ignore nullsafe.neverNull (titleType may be null in legacy data before migration)
                : Str::kebab($title->titleType?->slug ?? 'other');

            return [
                'title' => $title->value,
                'titleType' => $titleType,
                'language' => $title->language,
            ];
        })->toArray();
    }

    /**
     * Transform resource licenses to frontend format.
     *
     * @return array<int, string>
     */
    public function transformLicenses(Resource $resource): array
    {
        return $resource->rights
            ->filter(fn (Right $right): bool => CustomRightCatalogService::isSpdxRight($right))
            ->pluck('identifier')
            ->values()
            ->toArray();
    }

    /**
     * Transform stored resource-rights statements to editable custom/import rows.
     *
     * SPDX catalog rows remain in `initialLicenses`; unresolved imports and
     * linked custom catalog rights are shown as custom license rows in the editor.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transformRawRights(Resource $resource): array
    {
        return $resource->resourceRights
            ->sortBy('id')
            ->map(function ($resourceRight): array {
                $right = $resourceRight->right;

                if (CustomRightCatalogService::isSpdxRight($right)) {
                    return [];
                }

                $hasRawEvidence = false;
                foreach ([$resourceRight->rights_text, $resourceRight->rights_uri, $resourceRight->rights_identifier] as $value) {
                    if (is_string($value) && trim($value) !== '') {
                        $hasRawEvidence = true;
                        break;
                    }
                }

                if (! $right instanceof Right && ! $hasRawEvidence) {
                    return [];
                }

                return array_filter([
                    'sourceResourceRightId' => $resourceRight->id,
                    'rights' => $right instanceof Right ? $right->name : $resourceRight->rights_text,
                    'rightsUri' => $right instanceof Right ? ($right->uri ?? $resourceRight->rights_uri) : $resourceRight->rights_uri,
                    'rightsIdentifier' => $right instanceof Right ? null : $resourceRight->rights_identifier,
                    'rightsIdentifierScheme' => $right instanceof Right ? null : $resourceRight->rights_identifier_scheme,
                    'schemeUri' => $right instanceof Right ? null : $resourceRight->scheme_uri,
                    'lang' => $resourceRight->language,
                    'source' => $resourceRight->source,
                ], fn (mixed $value): bool => $value !== null && (! is_string($value) || trim($value) !== ''));
            })
            ->filter(fn (array $statement): bool => $statement !== [])
            ->values()
            ->all();
    }

    /**
     * Transform resource creators to authors and contributors format.
     *
     * @return array{authors: array<int, array<string, mixed>>, contributors: array<int, array<string, mixed>>}
     */
    public function transformCreators(Resource $resource): array
    {
        // Group ResourceCreator entries by their creatorable (Person/Institution)
        // to handle cases where same person has multiple ResourceCreator records
        $creatorableGroups = $resource->creators
            ->filter(function ($creator): bool {
                // Filter out MSL laboratories
                if ($creator->creatorable_type === Institution::class) {
                    /** @var Institution $institution */
                    $institution = $creator->creatorable;

                    return $institution->name_identifier_scheme !== 'labid';
                }

                return true;
            })
            ->groupBy(function ($creator): string {
                return $creator->creatorable_type.'_'.$creator->creatorable_id;
            });

        $authors = [];
        /** @var array<string, int> $authorIndexesByIdentity */
        $authorIndexesByIdentity = [];
        $contributors = [];

        foreach ($creatorableGroups as $group) {
            // In DataCite 4.6, all ResourceCreator entries are creators (no role distinction)
            /** @var ResourceCreator $firstEntry */
            $firstEntry = $group->first();
            $creatorable = $firstEntry->creatorable;

            $isContact = false;
            $email = null;
            $website = null;

            foreach ($group as $creatorEntry) {
                if (! (bool) $creatorEntry->is_contact) {
                    continue;
                }

                $isContact = true;
                $email ??= $creatorEntry->email;
                $website ??= $creatorEntry->website;
            }

            // Collect all unique affiliations from all entries of this creator
            $allAffiliations = $group->flatMap(function ($creator) {
                return $creator->affiliations;
            })->unique(function ($affiliation): string {
                // Unique by name and identifier combination
                return $affiliation->name.'|'.($affiliation->identifier ?? 'null');
            });

            // All ResourceCreator entries are creators in DataCite 4.6
            $data = [
                'position' => $firstEntry->position,
                'isContact' => $isContact,
            ];

            if ($isContact && $email !== null) {
                $data['email'] = $email;
            }

            if ($isContact && $website !== null) {
                $data['website'] = $website;
            }

            if ($firstEntry->creatorable_type === Person::class) {
                /** @var Person $creatorable */
                $data['type'] = 'person';
                // Map to frontend field names
                $data['firstName'] = $creatorable->given_name ?? '';
                $data['lastName'] = $creatorable->family_name ?? '';
                $data['orcid'] = $creatorable->name_identifier ?? '';
                // Mark stored ORCIDs as already verified to skip re-validation on load.
                // Only trust identifiers with ORCID scheme (or null for legacy data)
                // AND valid ORCID format+checksum (ISO 7064 MOD 11-2).
                $data['orcidVerified'] = $this->isVerifiedOrcid(
                    $creatorable->name_identifier,
                    $creatorable->name_identifier_scheme,
                );
            } elseif ($firstEntry->creatorable_type === Institution::class) {
                /** @var Institution $creatorable */
                $data['type'] = 'institution';
                $data['institutionName'] = $creatorable->name ?? '';
                $data['rorId'] = $creatorable->name_identifier ?? '';
            }

            // Add unique affiliations - map to frontend field names
            $data['affiliations'] = $allAffiliations->map(fn (Affiliation $affiliation): array => [
                'value' => $affiliation->name,
                'rorId' => $affiliation->identifier,
            ])->values()->toArray();

            $authors[] = $data;

            if ($creatorable instanceof Person) {
                $authorIndex = array_key_last($authors);

                foreach ($this->personIdentityKeys($creatorable) as $identityKey) {
                    $authorIndexesByIdentity[$identityKey] ??= $authorIndex;
                }
            }
        }

        // Transform ResourceContributor entries to contributors format
        foreach ($resource->contributors as $contributor) {
            /** @var ResourceContributor $contributor */
            if ($contributor->contributorable instanceof Institution
                && $contributor->contributorable->isLaboratory()) {
                continue;
            }

            $contributorTypes = $contributor->contributorTypes;
            $hasContactPersonRole = $contributorTypes
                ->contains(fn (ContributorType $ct): bool => $ct->slug === 'ContactPerson');
            $matchingAuthorIndex = $hasContactPersonRole
                ? $this->matchingAuthorIndexForContributor($contributor, $authorIndexesByIdentity)
                : null;

            if ($matchingAuthorIndex !== null) {
                $this->mergeContributorContactIntoAuthor($authors[$matchingAuthorIndex], $contributor);

                $contributorTypes = $contributorTypes
                    ->reject(fn (ContributorType $ct): bool => $ct->slug === 'ContactPerson')
                    ->values();

                if ($contributorTypes->isEmpty()) {
                    continue;
                }
            }

            $data = [
                'position' => $contributor->position,
                'roles' => $contributorTypes->pluck('name')->values()->toArray(),
            ];

            if ($contributor->contributorable_type === Person::class) {
                /** @var Person $person */
                $person = $contributor->contributorable;
                $data['type'] = 'person';
                $data['firstName'] = $person->given_name ?? '';
                $data['lastName'] = $person->family_name ?? '';
                $data['orcid'] = $person->name_identifier ?? '';
                // Mark stored ORCIDs as already verified to skip re-validation on load.
                // Only trust identifiers with ORCID scheme (or null for legacy data)
                // AND valid ORCID format+checksum (ISO 7064 MOD 11-2).
                $data['orcidVerified'] = $this->isVerifiedOrcid(
                    $person->name_identifier,
                    $person->name_identifier_scheme,
                );

                $hasVisibleContactPersonRole = $contributorTypes
                    ->contains(fn (ContributorType $ct): bool => $ct->slug === 'ContactPerson');
                $data['email'] = $hasVisibleContactPersonRole ? ($contributor->email ?? '') : '';
                $data['website'] = $hasVisibleContactPersonRole ? ($contributor->website ?? '') : '';
            } elseif ($contributor->contributorable_type === Institution::class) {
                /** @var Institution $institution */
                $institution = $contributor->contributorable;
                $data['type'] = 'institution';
                $data['institutionName'] = $institution->name ?? '';
                $data['rorId'] = $institution->name_identifier ?? '';
            }

            // Add affiliations from the contributor
            $data['affiliations'] = $contributor->affiliations->map(fn (Affiliation $affiliation): array => [
                'value' => $affiliation->name,
                'rorId' => $affiliation->identifier,
            ])->values()->toArray();

            $contributors[] = $data;
        }

        // Sort by position
        usort($authors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);
        usort($contributors, fn (array $a, array $b): int => $a['position'] <=> $b['position']);

        return [
            'authors' => $authors,
            'contributors' => $contributors,
        ];
    }

    /**
     * @param  array<string, int>  $authorIndexesByIdentity
     */
    private function matchingAuthorIndexForContributor(ResourceContributor $contributor, array $authorIndexesByIdentity): ?int
    {
        if ($contributor->contributorable_type !== Person::class) {
            return null;
        }

        $person = $contributor->contributorable;

        if (! $person instanceof Person) {
            return null;
        }

        foreach ($this->personIdentityKeys($person) as $identityKey) {
            if (array_key_exists($identityKey, $authorIndexesByIdentity)) {
                return $authorIndexesByIdentity[$identityKey];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function personIdentityKeys(Person $person): array
    {
        $keys = ["person-id:{$person->id}"];

        $orcidIdentityKey = $this->personOrcidIdentityKey($person);

        if ($orcidIdentityKey !== null) {
            $keys[] = $orcidIdentityKey;

            return array_values(array_unique($keys));
        }

        $normalisedName = $this->normalisePersonIdentityName($person);

        if ($normalisedName !== null) {
            $keys[] = 'name:'.$normalisedName;
        }

        return array_values(array_unique($keys));
    }

    private function personOrcidIdentityKey(Person $person): ?string
    {
        if ($person->name_identifier !== null && trim($person->name_identifier) !== '') {
            $scheme = $person->name_identifier_scheme ?? 'ORCID';

            if (strtoupper($scheme) === 'ORCID') {
                $bareOrcid = OrcidNormalizer::extractBareId($person->name_identifier);

                if ($bareOrcid !== '' && OrcidNormalizer::isValidFormat($bareOrcid)) {
                    return 'orcid:'.strtolower($bareOrcid);
                }
            }
        }

        return null;
    }

    private function normalisePersonIdentityName(Person $person): ?string
    {
        $givenName = trim((string) ($person->given_name ?? ''));
        $familyName = trim((string) ($person->family_name ?? ''));

        if ($givenName === '' || $familyName === '') {
            return null;
        }

        $normalised = mb_strtolower($familyName.'|'.$givenName, 'UTF-8');
        $normalised = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalised) ?: $normalised;
        $normalised = preg_replace('/[^\p{L}\p{N}|]+/u', ' ', $normalised) ?? '';
        $normalised = preg_replace('/\s+/', ' ', $normalised) ?? '';

        return trim($normalised);
    }

    /**
     * @param  array<string, mixed>  $author
     */
    private function mergeContributorContactIntoAuthor(array &$author, ResourceContributor $contributor): void
    {
        $author['isContact'] = true;

        if (($author['email'] ?? '') === '' && $contributor->email !== null) {
            $author['email'] = $contributor->email;
        }

        if (($author['website'] ?? '') === '' && $contributor->website !== null) {
            $author['website'] = $contributor->website;
        }
    }

    /**
     * Transform resource descriptions to frontend format.
     *
     * The database stores description type slugs in PascalCase (e.g., 'Abstract', 'SeriesInformation')
     * because they were seeded from the DataCite schema's camelCase naming convention. The frontend
     * expects specific display names (e.g., 'Abstract', 'Series Information'), so we:
     * 1. Convert PascalCase to kebab-case for consistent DESCRIPTION_TYPE_MAP lookup
     * 2. Map to the frontend display format which uses PascalCase with spaces
     *
     * This round-trip conversion exists because the database schema predates the frontend naming
     * conventions. A future migration could normalize database slugs to kebab-case to simplify
     * this logic.
     *
     * @return array<int, array{type: string, description: string, language: string|null}>
     */
    public function transformDescriptions(Resource $resource): array
    {
        return $resource->descriptions->map(function ($description): array {
            // Map description_type slug to frontend format
            // Use Str::kebab() to normalize slugs since DB stores PascalCase (e.g., 'SeriesInformation' → 'series-information')
            // @phpstan-ignore nullCoalesce.expr (defensive coding)
            $typeSlug = Str::kebab($description->descriptionType?->slug ?? 'other');
            $frontendType = self::DESCRIPTION_TYPE_MAP[$typeSlug] ?? 'Other';
            $editableDescription = $description->landing_page_html ?? $description->value;

            return [
                'type' => $frontendType,
                'description' => $editableDescription,
                'language' => $description->language,
            ];
        })->toArray();
    }

    /**
     * Transform resource dates to frontend format.
     *
     * Excludes system-managed and coverage dates that are not editable in the Dates section.
     * Preserves full ISO 8601 datetime+timezone values for dates that include time components.
     *
     * @return array<int, array{dateType: string, dateMode: 'single'|'range', startDate: string, endDate: string}>
     */
    public function transformDates(Resource $resource): array
    {
        return $resource->dates
            ->filter(function (ResourceDate $date): bool {
                $slug = mb_strtolower($date->dateType->slug);

                return ! in_array($slug, ['coverage', 'accepted', 'issued', 'updated'], true);
            })
            ->map(function (ResourceDate $date): array {
                $dateType = $date->dateType->slug;
                $dateTypeSlug = Str::kebab(mb_strtolower($dateType));
                $hasClosedRange = ($date->start_date ?? '') !== '' && ($date->end_date ?? '') !== '';

                $dateMode = $hasClosedRange && in_array($dateTypeSlug, ['created', 'collected', 'valid', 'other'], true)
                    ? 'range'
                    : 'single';

                return [
                    'dateType' => $dateType,
                    'dateMode' => $dateMode,
                    'startDate' => $this->formatStoredDate($date->start_date ?? $date->date_value),
                    'endDate' => $dateMode === 'range' ? $this->formatStoredDate($date->end_date) : '',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Format a stored date value for frontend consumption.
     *
     * Preserves full ISO 8601 datetime+timezone values (e.g., "2022-10-06T09:35+01:00")
     * instead of stripping time information. For legacy date-only values, formats as Y-m-d.
     *
     * @see https://github.com/McNamara84/ernie/issues/508
     */
    private function formatStoredDate(?string $dateValue): string
    {
        if (empty($dateValue)) {
            return '';
        }

        // If it contains a time component, preserve the full ISO 8601 value
        if (str_contains($dateValue, 'T')) {
            return $dateValue;
        }

        // Preserve partial date precision (YYYY, YYYY-MM) instead of expanding via Carbon
        if (preg_match('/^\d{4}$/', $dateValue) || preg_match('/^\d{4}-\d{2}$/', $dateValue)) {
            return $dateValue;
        }

        // Full date values: normalize via Carbon to ensure Y-m-d format
        try {
            return Carbon::parse($dateValue)->format('Y-m-d');
        } catch (\Exception) {
            return '';
        }
    }

    /**
     * Transform free keywords from subjects.
     *
     * @return array<int, string>
     */
    public function transformFreeKeywords(Resource $resource): array
    {
        return $resource->subjects
            ->filter(fn ($subject): bool => empty($subject->subject_scheme))
            ->pluck('value')
            ->toArray();
    }

    /**
     * Transform GCMD controlled keywords from subjects.
     *
     * @return array<int, array{id: string, text: string, path: string, scheme: string, schemeURI: string, language: string, classificationCode?: string}>
     */
    public function transformGcmdKeywords(Resource $resource): array
    {
        return $resource->subjects
            ->filter(fn ($subject): bool => ! empty($subject->subject_scheme)
                && $subject->subject_scheme !== GemetVocabularyParser::SCHEME_TITLE)
            ->map(function ($subject): array {
                $path = SubjectBreadcrumbPath::preferredPath($subject->breadcrumb_path, $subject->value) ?? $subject->value;

                return [
                    'id' => $subject->value_uri ?? $subject->classification_code ?? '',
                    'text' => SubjectBreadcrumbPath::leaf($path, $subject->value) ?? $subject->value,
                    'path' => $path,
                    'scheme' => $subject->subject_scheme ?? '',
                    'schemeURI' => $subject->scheme_uri ?? '',
                    'language' => 'en',
                    ...($subject->classification_code !== null ? ['classificationCode' => $subject->classification_code] : []),
                ];
            })->values()->toArray();
    }

    /**
     * Transform GEMET controlled keywords from subjects.
     *
     * @return array<int, array{id: string, text: string, path: string, scheme: string, schemeURI: string, language: string, classificationCode?: string}>
     */
    public function transformGemetKeywords(Resource $resource): array
    {
        return $resource->subjects
            ->filter(fn ($subject): bool => $subject->subject_scheme === GemetVocabularyParser::SCHEME_TITLE)
            ->map(function ($subject): array {
                $path = SubjectBreadcrumbPath::preferredPath($subject->breadcrumb_path, $subject->value) ?? $subject->value;

                return [
                    'id' => $subject->value_uri ?? $subject->classification_code ?? '',
                    'text' => SubjectBreadcrumbPath::leaf($path, $subject->value) ?? $subject->value,
                    'path' => $path,
                    'scheme' => $subject->subject_scheme ?? '',
                    'schemeURI' => $subject->scheme_uri ?? '',
                    'language' => 'en',
                    ...($subject->classification_code !== null ? ['classificationCode' => $subject->classification_code] : []),
                ];
            })->values()->toArray();
    }

    /**
     * Transform geoLocations to coverages format for frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transformCoverages(Resource $resource): array
    {
        return $resource->geoLocations->map(function ($geoLocation): array {
            // Determine type from explicit geo_type column or fall back to implicit detection
            $type = $geoLocation->geo_type;

            if ($type === null) {
                if ($geoLocation->polygon_points !== null && count($geoLocation->polygon_points) >= 3) {
                    $type = 'polygon';
                } elseif ($geoLocation->west_bound_longitude !== null) {
                    $type = 'box';
                } else {
                    $type = 'point';
                }
            }

            $entry = [
                'id' => (string) $geoLocation->id,
                'type' => $type,
                'latMin' => '',
                'latMax' => '',
                'lonMin' => '',
                'lonMax' => '',
                'startDate' => '',
                'endDate' => '',
                'startTime' => '',
                'endTime' => '',
                'timezone' => 'UTC',
                'description' => $geoLocation->place ?? '',
            ];

            match ($type) {
                'point' => $entry = array_merge($entry, [
                    'latMin' => $geoLocation->point_latitude !== null ? (string) $geoLocation->point_latitude : '',
                    'lonMin' => $geoLocation->point_longitude !== null ? (string) $geoLocation->point_longitude : '',
                ]),
                'box' => $entry = array_merge($entry, [
                    'latMin' => $geoLocation->south_bound_latitude !== null ? (string) $geoLocation->south_bound_latitude : '',
                    'latMax' => $geoLocation->north_bound_latitude !== null ? (string) $geoLocation->north_bound_latitude : '',
                    'lonMin' => $geoLocation->west_bound_longitude !== null ? (string) $geoLocation->west_bound_longitude : '',
                    'lonMax' => $geoLocation->east_bound_longitude !== null ? (string) $geoLocation->east_bound_longitude : '',
                ]),
                'polygon', 'line' => $entry = array_merge($entry, [
                    'polygonPoints' => $geoLocation->polygon_points !== null
                        ? array_map(fn (array $point): array => [
                            'lat' => (float) $point['latitude'],
                            'lon' => (float) $point['longitude'],
                        ], $geoLocation->polygon_points)
                        : [],
                ]),
                default => null,
            };

            return $entry;
        })->toArray();
    }

    /**
     * Transform related identifiers to frontend format.
     *
     * @return array<int, array{id: int, identifier: string, identifier_type: string, relation_type: string, relation_type_information: string|null, citation_label: string|null, source: string|null, is_repository_curation: bool}>
     */
    public function transformRelatedIdentifiers(Resource $resource): array
    {
        return $resource->relatedIdentifiers
            ->sortBy('position')
            ->map(fn (RelatedIdentifier $relatedId): array => [
                'id' => $relatedId->id,
                'identifier' => $relatedId->identifier,
                'identifier_type' => $relatedId->identifierType->slug,
                'relation_type' => $relatedId->relationType->slug,
                'relation_type_information' => $relatedId->relation_type_information,
                'citation_label' => $relatedId->citation_label,
                'source' => $relatedId->source,
                'is_repository_curation' => $relatedId->isRepositoryCuration(),
            ])
            ->values()
            ->toArray();
    }

    /**
     * Transform funding references to frontend format.
     *
     * @return array<int, array<string, string>>
     */
    public function transformFundingReferences(Resource $resource): array
    {
        return $resource->fundingReferences
            ->sortBy('position')
            ->map(function ($funding): array {
                /** @var FundingReference $funding */
                $identifierType = $funding->funderIdentifierType;

                return [
                    'funderName' => $funding->funder_name,
                    'funderIdentifier' => $funding->funder_identifier ?? '',
                    'funderIdentifierType' => $identifierType !== null ? $identifierType->name : '',
                    'awardNumber' => $funding->award_number ?? '',
                    'awardUri' => $funding->award_uri ?? '',
                    'awardTitle' => $funding->award_title ?? '',
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Transform MSL Laboratories from contributors with labid identifier scheme.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transformMslLaboratories(Resource $resource): array
    {
        return $resource->contributors
            ->sortBy('position')
            ->filter(function ($contributor): bool {
                if ($contributor->contributorable_type === Institution::class) {
                    /** @var Institution $institution */
                    $institution = $contributor->contributorable;

                    return $institution->isLaboratory();
                }

                return false;
            })
            ->map(function ($contributor): array {
                /** @var Institution $institution */
                $institution = $contributor->contributorable;
                $affiliation = $contributor->affiliations->first();

                return [
                    'identifier' => $institution->name_identifier ?? '',
                    'name' => $institution->name ?? '',
                    'affiliation_name' => $affiliation->name ?? '',
                    'affiliation_ror' => $affiliation->identifier ?? null,
                    'position' => $contributor->position,
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * Transform resource instruments to editor format.
     *
     * @return array<int, array{pid: string, pidType: string, name: string}>
     */
    public function transformInstruments(Resource $resource): array
    {
        return $resource->instruments
            ->map(fn ($instrument): array => [
                'pid' => $instrument->instrument_pid,
                'pidType' => $instrument->instrument_pid_type,
                'name' => $instrument->instrument_name,
            ])
            ->toArray();
    }

    /**
     * Check if a stored identifier qualifies as a verified ORCID.
     *
     * Returns true only when the identifier has a valid ORCID format (XXXX-XXXX-XXXX-XXXX)
     * and passes the ISO 7064 MOD 11-2 checksum, and the scheme is 'ORCID' or null (legacy data).
     * Accepts both bare IDs and full ORCID URLs.
     */
    private function isVerifiedOrcid(?string $identifier, ?string $scheme): bool
    {
        if ($identifier === null || trim($identifier) === '') {
            return false;
        }

        if ($scheme !== null && strtoupper($scheme) !== 'ORCID') {
            return false;
        }

        return OrcidNormalizer::isValid($identifier);
    }
}
