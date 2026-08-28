<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\IdentifierType;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\Person;
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
use App\Models\ResourceRight;
use App\Models\Right;
use App\Models\Subject;
use App\Models\Title;
use App\Services\Igsn\IgsnDescriptionNormalizerService;
use App\Services\Rights\CustomRightCatalogService;
use App\Support\IgsnIdentifier;
use App\Support\PortalSubjectNormalizer;
use App\Support\SubjectBreadcrumbPath;
use Illuminate\Database\Eloquent\Collection;

final class LandingPageResourceTransformer
{
    private readonly IgsnSampleFamilyService $sampleFamilyService;

    private readonly IgsnDescriptionNormalizerService $igsnDescriptionNormalizer;

    private readonly IgsnRepositoryContactService $repositoryContactService;

    private readonly LandingPagePersonIdentityResolverService $personIdentityResolver;

    public function __construct(
        ?IgsnSampleFamilyService $sampleFamilyService = null,
        ?IgsnDescriptionNormalizerService $igsnDescriptionNormalizer = null,
        ?IgsnRepositoryContactService $repositoryContactService = null,
        ?LandingPagePersonIdentityResolverService $personIdentityResolver = null,
    ) {
        $this->sampleFamilyService = $sampleFamilyService ?? new IgsnSampleFamilyService;
        $this->igsnDescriptionNormalizer = $igsnDescriptionNormalizer ?? new IgsnDescriptionNormalizerService;
        $this->repositoryContactService = $repositoryContactService ?? new IgsnRepositoryContactService;
        $this->personIdentityResolver = $personIdentityResolver ?? new LandingPagePersonIdentityResolverService;
    }

    /**
     * @return array<int, string>
     */
    public function requiredRelations(): array
    {
        return [
            'creators.creatorable',
            'creators.affiliations',
            'contributors.contributorable',
            'contributors.contributorTypes',
            'contributors.affiliations',
            'titles.titleType',
            'descriptions.descriptionType',
            'resourceRights.right',
            'subjects',
            'geoLocations',
            'dates.dateType',
            'relatedIdentifiers.identifierType',
            'relatedIdentifiers.relationType',
            'relatedItems.relationType',
            'relatedItems.titles',
            'relatedItems.creators.affiliations',
            'relatedItems.contributors.affiliations',
            'fundingReferences.funderIdentifierType',
            'resourceType',
            'language',
            'publisher',
            'igsnMetadata.parentResource.landingPage.externalDomain',
            'igsnClassifications',
            'igsnGeologicalUnits',
            'alternateIdentifiers',
            'sizes',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(Resource $resource): array
    {
        $resourceData = $resource->toArray();

        // Publisher metadata is used server-side for CSL formatting. Keep the
        // relation object out of the stable, scalar frontend resource contract.
        unset($resourceData['publisher'], $resourceData['rights'], $resourceData['resource_rights']);

        $resourceData['titles'] = $resource->titles
            ->map(static fn (Title $title): array => [
                'id' => $title->id,
                'title' => $title->value,
                // Use null-safe operator for legacy data where titleType may be null
                /** @phpstan-ignore nullsafe.neverNull (titleType may be null in legacy data before migration) */
                'title_type' => $title->titleType?->slug ?? 'MainTitle',
                'language' => $title->language,
            ])
            ->all();

        $resourceData['related_identifiers'] = $resource->relatedIdentifiers
            ->map(static function (RelatedIdentifier $relatedId): array {
                /** @var IdentifierType|null $identifierType */
                $identifierType = $relatedId->identifierType;
                /** @var RelationType|null $relationType */
                $relationType = $relatedId->relationType;

                return [
                    'id' => $relatedId->id,
                    'identifier' => $relatedId->identifier,
                    'identifier_type' => $identifierType !== null ? $identifierType->slug : null,
                    'relation_type' => $relationType !== null ? $relationType->slug : null,
                    'citation_label' => $relatedId->citation_label,
                    'source' => $relatedId->source,
                    'is_repository_curation' => $relatedId->isRepositoryCuration(),
                    'position' => $relatedId->position,
                    'igsn' => $identifierType?->slug === 'DOI'
                        ? IgsnIdentifier::handleFromDoi($relatedId->identifier)
                        : null,
                ];
            })
            ->all();

        $relatedItems = $resource->relationLoaded('relatedItems')
            ? $resource->relatedItems
            : new Collection;

        $resourceData['related_items'] = $relatedItems
            ->sortBy('position')
            ->values()
            ->map(static function (RelatedItem $item): array {
                /** @var RelationType|null $relationType */
                $relationType = $item->relationType;

                return [
                    'id' => $item->id,
                    'related_item_type' => $item->related_item_type,
                    'relation_type' => $relationType?->name,
                    'relation_type_slug' => $relationType?->slug,
                    'publication_year' => $item->publication_year,
                    'volume' => $item->volume,
                    'issue' => $item->issue,
                    'number' => $item->number,
                    'number_type' => $item->number_type,
                    'first_page' => $item->first_page,
                    'last_page' => $item->last_page,
                    'publisher' => $item->publisher,
                    'edition' => $item->edition,
                    'identifier' => $item->identifier,
                    'identifier_type' => $item->identifier_type,
                    'related_metadata_scheme' => $item->related_metadata_scheme,
                    'scheme_uri' => $item->scheme_uri,
                    'scheme_type' => $item->scheme_type,
                    'position' => $item->position,
                    'igsn' => $item->identifier_type === 'DOI' && $item->identifier !== null
                        ? IgsnIdentifier::handleFromDoi($item->identifier)
                        : null,
                    'titles' => $item->titles
                        ->map(static fn (RelatedItemTitle $title): array => [
                            'id' => $title->id,
                            'title' => $title->title,
                            'title_type' => $title->title_type,
                            'language' => $title->language,
                        ])
                        ->all(),
                    'creators' => $item->creators
                        ->sortBy('position')
                        ->values()
                        ->map(static fn (RelatedItemCreator $creator): array => [
                            'id' => $creator->id,
                            'name_type' => $creator->name_type,
                            'name' => $creator->name,
                            'given_name' => $creator->given_name,
                            'family_name' => $creator->family_name,
                            'name_identifier' => $creator->name_identifier,
                            'name_identifier_scheme' => $creator->name_identifier_scheme,
                            'scheme_uri' => $creator->scheme_uri,
                            'position' => $creator->position,
                            'affiliations' => $creator->affiliations
                                ->map(static fn (RelatedItemCreatorAffiliation $affiliation): array => [
                                    'id' => $affiliation->id,
                                    'name' => $affiliation->name,
                                    'affiliation_identifier' => $affiliation->affiliation_identifier,
                                    'scheme' => $affiliation->scheme,
                                ])
                                ->all(),
                        ])
                        ->all(),
                    'contributors' => $item->contributors
                        ->sortBy('position')
                        ->values()
                        ->map(static fn (RelatedItemContributor $contributor): array => [
                            'id' => $contributor->id,
                            'contributor_type' => $contributor->contributor_type,
                            'name_type' => $contributor->name_type,
                            'name' => $contributor->name,
                            'given_name' => $contributor->given_name,
                            'family_name' => $contributor->family_name,
                            'name_identifier' => $contributor->name_identifier,
                            'name_identifier_scheme' => $contributor->name_identifier_scheme,
                            'scheme_uri' => $contributor->scheme_uri,
                            'position' => $contributor->position,
                            'affiliations' => $contributor->affiliations
                                ->map(static fn (RelatedItemContributorAffiliation $affiliation): array => [
                                    'id' => $affiliation->id,
                                    'name' => $affiliation->name,
                                    'affiliation_identifier' => $affiliation->affiliation_identifier,
                                    'scheme' => $affiliation->scheme,
                                ])
                                ->all(),
                        ])
                        ->all(),
                ];
            })
            ->all();

        $descriptionFormattingService = new DescriptionFormattingService;

        $displayIdentityKeys = $this->personIdentityResolver->resolve($resource);

        $resourceData['descriptions'] = $resource->descriptions
            ->map(function (Description $desc) use ($descriptionFormattingService): array {
                /** @var DescriptionType|null $descriptionType */
                $descriptionType = $desc->descriptionType;

                return [
                    'id' => $desc->id,
                    'value' => $desc->value,
                    'landing_page_html' => $this->sanitizeLandingPageHtml($desc->landing_page_html, $descriptionFormattingService),
                    'description_type' => $descriptionType !== null ? $descriptionType->name : null,
                    'language' => $desc->language,
                ];
            })
            ->all();

        $resourceData['creators'] = $resource->creators
            ->map(static function (ResourceCreator $creator) use ($displayIdentityKeys): array {
                /** @var Person|Institution|null $creatorable */
                $creatorable = $creator->creatorable;

                return [
                    'id' => $creator->id,
                    'position' => $creator->position,
                    'display_identity_key' => $displayIdentityKeys['creators'][$creator->id] ?? null,
                    'affiliations' => $creator->affiliations
                        ->map(static fn (Affiliation $affiliation): array => [
                            'id' => $affiliation->id,
                            'name' => $affiliation->name,
                            'affiliation_identifier' => $affiliation->identifier,
                            'affiliation_identifier_scheme' => $affiliation->identifier_scheme,
                        ])
                        ->all(),
                    'creatorable' => [
                        'type' => class_basename($creator->creatorable_type),
                        'id' => $creatorable?->id,
                        'given_name' => $creatorable instanceof Person ? $creatorable->given_name : null,
                        'family_name' => $creatorable instanceof Person ? $creatorable->family_name : null,
                        'name_identifier' => $creatorable?->name_identifier,
                        'name_identifier_scheme' => $creatorable?->name_identifier_scheme,
                        'name' => $creatorable instanceof Institution ? $creatorable->name : null,
                    ],
                ];
            })
            ->all();

        $resourceData['contributors'] = $resource->contributors
            ->map(static function (ResourceContributor $contributor) use ($displayIdentityKeys): array {
                /** @var Person|Institution|null $contributorable */
                $contributorable = $contributor->contributorable;

                return [
                    'id' => $contributor->id,
                    'position' => $contributor->position,
                    'display_identity_key' => $displayIdentityKeys['contributors'][$contributor->id] ?? null,
                    'contributor_types' => $contributor->contributorTypes->map(
                        static fn (ContributorType $type): string => $type->name
                    )->values()->all(),
                    'affiliations' => $contributor->affiliations
                        ->map(static fn (Affiliation $affiliation): array => [
                            'id' => $affiliation->id,
                            'name' => $affiliation->name,
                            'affiliation_identifier' => $affiliation->identifier,
                            'affiliation_identifier_scheme' => $affiliation->identifier_scheme,
                        ])
                        ->all(),
                    'contributorable' => [
                        'type' => class_basename($contributor->contributorable_type),
                        'id' => $contributorable?->id,
                        'given_name' => $contributorable instanceof Person ? $contributorable->given_name : null,
                        'family_name' => $contributorable instanceof Person ? $contributorable->family_name : null,
                        'name_identifier' => $contributorable?->name_identifier,
                        'name_identifier_scheme' => $contributorable?->name_identifier_scheme,
                        'name' => $contributorable instanceof Institution ? $contributorable->name : null,
                    ],
                ];
            })
            ->all();

        $resourceData['funding_references'] = $resource->fundingReferences
            ->map(static fn ($funding): array => [
                'id' => $funding->id,
                'funder_name' => $funding->funder_name,
                'funder_identifier' => $funding->funder_identifier,
                'funder_identifier_type' => $funding->funderIdentifierType?->name,
                'award_number' => $funding->award_number,
                'award_uri' => $funding->award_uri,
                'award_title' => $funding->award_title,
                'position' => $funding->position,
            ])
            ->all();

        $resourceData['subjects'] = $resource->subjects
            ->map(function (Subject $subject): array {
                $breadcrumbPath = SubjectBreadcrumbPath::preferredPath($subject->breadcrumb_path, $subject->value);

                return [
                    'id' => $subject->id,
                    'subject' => SubjectBreadcrumbPath::leaf($breadcrumbPath, $subject->value) ?? $subject->value,
                    'subject_scheme' => PortalSubjectNormalizer::normalizeScheme($subject->subject_scheme),
                    'scheme_uri' => $subject->scheme_uri,
                    'value_uri' => $subject->value_uri,
                    'classification_code' => $subject->classification_code,
                    'breadcrumb_path' => $breadcrumbPath,
                ];
            })
            ->all();

        $resourceData['geo_locations'] = $resource->geoLocations
            ->map(static fn ($geo): array => [
                'id' => $geo->id,
                'place' => $geo->place,
                'geo_type' => $geo->geo_type,
                'point_longitude' => $geo->point_longitude !== null ? (float) $geo->point_longitude : null,
                'point_latitude' => $geo->point_latitude !== null ? (float) $geo->point_latitude : null,
                'west_bound_longitude' => $geo->west_bound_longitude !== null ? (float) $geo->west_bound_longitude : null,
                'east_bound_longitude' => $geo->east_bound_longitude !== null ? (float) $geo->east_bound_longitude : null,
                'south_bound_latitude' => $geo->south_bound_latitude !== null ? (float) $geo->south_bound_latitude : null,
                'north_bound_latitude' => $geo->north_bound_latitude !== null ? (float) $geo->north_bound_latitude : null,
                'polygon_points' => $geo->polygon_points,
                'elevation' => $geo->elevation !== null ? (float) $geo->elevation : null,
                'elevation_unit' => $geo->elevation_unit,
                'location_type' => $geo->location_type,
                'location_description' => $geo->location_description,
                'locality_description' => $geo->locality_description,
                'country' => $geo->country,
                'province' => $geo->province,
                'county' => $geo->county,
                'city' => $geo->city,
            ])
            ->all();

        $resourceData['licenses'] = $this->transformLicenses($resource);

        $creatorEntitiesByIdentity = [];

        foreach ($resource->creators as $creator) {
            $identityKey = $displayIdentityKeys['creators'][$creator->id] ?? null;
            $creatorable = $creator->getRelation('creatorable');

            if ($identityKey !== null
                && ! isset($creatorEntitiesByIdentity[$identityKey])
                && ($creatorable instanceof Person || $creatorable instanceof Institution)) {
                $creatorEntitiesByIdentity[$identityKey] = $creatorable;
            }
        }

        // 1. Collect creator contact persons (is_contact flag + has email)
        $creatorContactPersons = $resource->creators
            ->filter(static fn (ResourceCreator $creator): bool => $creator->is_contact && $creator->email !== null && $creator->email !== '')
            ->sortBy('position')
            ->unique(static fn (ResourceCreator $creator): string => $displayIdentityKeys['creators'][$creator->id]
                ?? $creator->creatorable_type.'|'.$creator->creatorable_id)
            ->values();

        // 2. Track resolved creator display identities for contact deduplication.
        $creatorIdentityKeys = $creatorContactPersons
            ->map(static fn (ResourceCreator $creator): string => $displayIdentityKeys['creators'][$creator->id]
                ?? $creator->creatorable_type.'|'.$creator->creatorable_id)
            ->all();

        $seenContactIdentityKeys = array_fill_keys($creatorIdentityKeys, true);

        // 3. Collect contributor contact persons (ContributorType "ContactPerson" + has email, deduplicated)
        $contributorContactPersons = $resource->contributors
            ->filter(static function (ResourceContributor $contributor) use ($displayIdentityKeys, &$seenContactIdentityKeys): bool {
                // Must have a non-empty email
                if ($contributor->email === null || $contributor->email === '') {
                    return false;
                }

                // Must have ContributorType with slug "ContactPerson"
                $hasContactPersonType = $contributor->contributorTypes
                    ->contains(static fn (ContributorType $type): bool => $type->slug === 'ContactPerson');

                if (! $hasContactPersonType) {
                    return false;
                }

                // Skip if the same resolved person already exists as a contact.
                $identityKey = $displayIdentityKeys['contributors'][$contributor->id]
                    ?? $contributor->contributorable_type.'|'.$contributor->contributorable_id;

                if (isset($seenContactIdentityKeys[$identityKey])) {
                    return false;
                }

                $seenContactIdentityKeys[$identityKey] = true;

                return true;
            })
            ->sortBy('position')
            ->values();

        // Helper to build display name from a Person or Institution entity
        $buildEntityName = static function (Person|Institution|null $entity): string {
            if ($entity instanceof Person) {
                return trim(implode(' ', array_filter([$entity->given_name, $entity->family_name]))) ?: 'Contact Person';
            }

            if ($entity instanceof Institution) {
                return $entity->name ?? 'Contact Person';
            }

            return 'Contact Person';
        };

        // Helper to map a contact person entry (shared between creators and contributors)
        $mapContactEntry = static function (
            int $id,
            Person|Institution|null $entity,
            string $morphType,
            string $source,
            array $affiliations,
            ?string $website,
            Person|Institution|null $displayEntity = null,
        ) use ($buildEntityName): array {
            $visibleEntity = $displayEntity ?? $entity;
            $isPerson = $visibleEntity instanceof Person;
            $givenName = $isPerson ? $visibleEntity->given_name : null;
            $familyName = $isPerson ? $visibleEntity->family_name : null;
            $nameIdentifierScheme = $visibleEntity?->name_identifier_scheme;
            $nameIdentifier = $visibleEntity?->name_identifier;

            return [
                'id' => $id,
                'name' => $buildEntityName($visibleEntity),
                'given_name' => $givenName,
                'family_name' => $familyName,
                'type' => $visibleEntity !== null ? class_basename($visibleEntity) : class_basename($morphType),
                'source' => $source,
                'affiliations' => $affiliations,
                'orcid' => $nameIdentifierScheme === 'ORCID'
                    ? $nameIdentifier
                    : null,
                'website' => $website,
                'has_email' => true,
            ];
        };

        // 4. Map creator contact persons
        $mappedCreators = $creatorContactPersons
            ->map(static fn (ResourceCreator $creator): array => $mapContactEntry(
                $creator->id,
                $creator->creatorable,
                $creator->creatorable_type,
                'creator',
                $creator->affiliations->map(static fn (Affiliation $aff): array => [
                    'name' => $aff->name,
                    'identifier' => $aff->identifier,
                    'scheme' => $aff->identifier_scheme,
                ])->all(),
                $creator->website,
            ));

        // 5. Map contributor contact persons
        $mappedContributors = $contributorContactPersons
            ->map(static function (ResourceContributor $contributor) use (
                $creatorEntitiesByIdentity,
                $displayIdentityKeys,
                $mapContactEntry,
            ): array {
                $identityKey = $displayIdentityKeys['contributors'][$contributor->id] ?? null;

                return $mapContactEntry(
                    $contributor->id,
                    $contributor->contributorable,
                    $contributor->contributorable_type,
                    'contributor',
                    $contributor->affiliations->map(static fn (Affiliation $aff): array => [
                        'name' => $aff->name,
                        'identifier' => $aff->identifier,
                        'scheme' => $aff->identifier_scheme,
                    ])->all(),
                    $contributor->website,
                    $identityKey !== null ? ($creatorEntitiesByIdentity[$identityKey] ?? null) : null,
                );
            });

        // 6. Merge: creators first, then contributors
        $resourceData['contact_persons'] = $mappedCreators->concat($mappedContributors)->values()->all();

        // Transform dates to a flat shape (date_type as string).
        // Defensive: relation may not be eager-loaded in unit tests.
        $dates = $resource->relationLoaded('dates')
            ? $resource->dates
            : new Collection;

        $resourceData['dates'] = $dates
            ->map(static function (ResourceDate $date): array {
                $dateType = $date->relationLoaded('dateType') ? $date->dateType : null;

                return [
                    'id' => $date->id,
                    'date_type' => $dateType?->name,
                    'date_type_slug' => $dateType?->slug,
                    'date_value' => $date->date_value,
                    'start_date' => $date->start_date,
                    'end_date' => $date->end_date,
                    'date_information' => $date->date_information,
                ];
            })
            ->all();

        // IGSN-specific metadata (only present for PhysicalObject resources)
        if ($resource->relationLoaded('igsnMetadata') && $resource->igsnMetadata !== null) {
            /** @var IgsnMetadata $meta */
            $meta = $resource->igsnMetadata;
            $parent = $meta->parentResource;
            $parentLandingPage = $parent?->landingPage;
            $descriptionJson = $meta->description_json ?? [];
            $descriptionGroups = $this->igsnDescriptionNormalizer->normalizeCsvPayload($descriptionJson);
            $legacyDescriptions = array_values(array_filter(
                is_array($descriptionJson['material_descriptions'] ?? null) ? $descriptionJson['material_descriptions'] : [],
                static fn (mixed $description): bool => is_string($description) && trim($description) !== '',
            ));
            if ($descriptionGroups === [] && $legacyDescriptions !== []) {
                $descriptionGroups = [[
                    'entries' => array_map(
                        static fn (string $description): array => ['value' => trim($description), 'scheme' => null],
                        $legacyDescriptions,
                    ),
                ]];
            }
            $igsn = $resource->doi !== null ? IgsnIdentifier::handleFromDoi($resource->doi) : null;
            $parentDoi = $parent?->doi;
            $parentIgsn = $parentDoi !== null ? IgsnIdentifier::handleFromDoi($parentDoi) : null;

            if ($parentIgsn === null && is_string($descriptionJson['parent_igsn_handle'] ?? null)) {
                $normalizedParent = IgsnIdentifier::normalizeInputToDoi($descriptionJson['parent_igsn_handle']);
                $parentDoi ??= $normalizedParent;
                $parentIgsn = $normalizedParent !== null ? IgsnIdentifier::handleFromDoi($normalizedParent) : null;
            }

            if ($parentIgsn === null) {
                $partOf = $resource->relatedIdentifiers->first(
                    static fn (RelatedIdentifier $identifier): bool => $identifier->relationType->slug === 'IsPartOf'
                        && IgsnIdentifier::normalizeInputToDoi($identifier->identifier) !== null,
                );
                if ($partOf !== null) {
                    $parentDoi = IgsnIdentifier::normalizeInputToDoi($partOf->identifier);
                    $parentIgsn = $parentDoi !== null ? IgsnIdentifier::handleFromDoi($parentDoi) : null;
                }
            }

            $alternateIdentifiers = $resource->relationLoaded('alternateIdentifiers')
                ? $resource->alternateIdentifiers
                : new Collection;
            $sizes = $resource->relationLoaded('sizes') ? $resource->sizes : new Collection;
            $geologicalUnits = $resource->relationLoaded('igsnGeologicalUnits')
                ? $resource->igsnGeologicalUnits
                : new Collection;
            $name = $alternateIdentifiers
                ->sortBy('position')
                ->first(static fn ($identifier): bool => strcasecmp($identifier->type, 'Local accession number') === 0)
                ?->value;
            $originalArchive = $meta->original_archive ?? ($descriptionJson['original_archive'] ?? null);
            $originalArchiveContact = $meta->original_archive_contact ?? ($descriptionJson['original_archive_contact'] ?? null);
            $currentRepositoryContact = $this->repositoryContactService->publicDescriptor(
                IgsnRepositoryContactService::TYPE_CURRENT,
                $meta->current_archive_contact,
                $meta->current_archive,
            );
            $originalRepositoryContact = $this->repositoryContactService->publicDescriptor(
                IgsnRepositoryContactService::TYPE_ORIGINAL,
                is_string($originalArchiveContact) ? $originalArchiveContact : null,
                is_string($originalArchive) ? $originalArchive : null,
            );
            $repositoryContacts = array_values(array_filter([$currentRepositoryContact, $originalRepositoryContact]));
            $sampleImageUrl = $meta->sampleImageUrl();
            $sampleImageHosting = $meta->sampleImageHosting();

            $resourceData['igsn_metadata'] = [
                'igsn' => $igsn,
                'name' => $name,
                'user_code' => $meta->user_code,
                'sample_type' => $meta->sample_type,
                'material' => $meta->material,
                'cruise_field_program' => $meta->cruise_field_program,
                'sample_purpose' => $meta->sample_purpose,
                'depth_min' => $meta->depth_min,
                'depth_max' => $meta->depth_max,
                'depth_scale' => $meta->depth_scale,
                'collection_method' => $meta->collection_method,
                'collection_method_description' => $meta->collection_method_description,
                'collection_date_precision' => $meta->collection_date_precision,
                'coordinate_system' => $meta->coordinate_system,
                'sample_access' => $meta->sample_access,
                'description_groups' => $descriptionGroups,
                'material_descriptions' => $legacyDescriptions,
                'comments' => array_values(array_filter(
                    is_array($descriptionJson['comments'] ?? null) ? $descriptionJson['comments'] : [],
                    static fn (mixed $comment): bool => is_string($comment) && trim($comment) !== '',
                )),
                'current_archive' => $meta->current_archive,
                'current_archive_contact' => $currentRepositoryContact['label'] ?? null,
                'original_archive' => $originalArchive,
                'original_archive_contact' => $originalRepositoryContact['label'] ?? null,
                'repository_contacts' => $repositoryContacts,
                'sample_image' => $sampleImageUrl !== null && $sampleImageHosting !== null ? [
                    'url' => $sampleImageUrl,
                    'hosting' => $sampleImageHosting,
                ] : null,
                'platform_type' => $meta->platform_type,
                'platform_name' => $meta->platform_name,
                'platform_description' => $meta->platform_description,
                'sizes' => $sizes->map(static fn ($size): array => [
                    'id' => $size->id,
                    'numeric_value' => $size->numeric_value,
                    'unit' => $size->unit,
                    'type' => $size->type,
                    'label' => $size->export_string,
                ])->all(),
                'geological_units' => $geologicalUnits->sortBy('position')->values()->map(static fn ($unit): array => [
                    'id' => $unit->id,
                    'value' => $unit->value,
                ])->all(),
                'parent' => $parentIgsn === null ? null : [
                    'igsn' => $parentIgsn,
                    'doi' => $parentDoi,
                    'landing_page' => ($parentLandingPage !== null && $parentLandingPage->status === 'published')
                        ? ['public_url' => $parentLandingPage->public_url]
                        : null,
                ],
            ];

            $resourceData['igsn_classifications'] = ($resource->relationLoaded('igsnClassifications')
                ? $resource->igsnClassifications
                : new Collection)
                ->sortBy('position')
                ->values()
                ->map(static fn (IgsnClassification $classification): array => [
                    'id' => $classification->id,
                    'value' => $classification->value,
                    'classification_type' => $classification->classification_type?->value,
                ])
                ->all();

            $resourceData['igsn_sample_family'] = $this->sampleFamilyService->forResource($resource);
        } else {
            $resourceData['igsn_metadata'] = null;
            $resourceData['igsn_classifications'] = [];
            $resourceData['igsn_sample_family'] = null;
        }

        return $resourceData;
    }

    /**
     * Include both trusted catalog rights and unresolved imported statements.
     *
     * `resource_rights` is the source of truth because every row represents one
     * DataCite rights statement. Keeping the row boundary prevents a linked
     * statement from also being emitted as an unresolved duplicate.
     *
     * @return list<array<string, int|string|null>>
     */
    private function transformLicenses(Resource $resource): array
    {
        if (! $resource->relationLoaded('resourceRights')) {
            // Preserve compatibility for direct transformer callers that still
            // preload only the historical belongs-to-many relation.
            return array_values($resource->rights
                ->map(static fn (Right $right): array => [
                    'id' => $right->id,
                    'resource_right_id' => null,
                    'name' => $right->name,
                    'spdx_id' => CustomRightCatalogService::isSpdxRight($right) ? $right->identifier : null,
                    'reference' => $right->uri,
                    'scheme_uri' => $right->scheme_uri,
                    'source' => 'catalog',
                ])
                ->all());
        }

        return array_values($resource->resourceRights
            ->map(function (ResourceRight $resourceRight): ?array {
                $right = $resourceRight->right;

                if ($right instanceof Right) {
                    return [
                        'id' => $right->id,
                        'resource_right_id' => $resourceRight->id,
                        'name' => $right->name,
                        'spdx_id' => CustomRightCatalogService::isSpdxRight($right) ? $right->identifier : null,
                        'reference' => $right->uri,
                        'scheme_uri' => $right->scheme_uri,
                        'source' => 'catalog',
                    ];
                }

                $name = $this->firstNonEmptyString(
                    $resourceRight->rights_text,
                    $resourceRight->rights_identifier,
                    $resourceRight->rights_uri,
                );

                if ($name === null) {
                    return null;
                }

                return [
                    'id' => null,
                    'resource_right_id' => $resourceRight->id,
                    'name' => $name,
                    'spdx_id' => null,
                    'reference' => $this->normalizedString($resourceRight->rights_uri),
                    'scheme_uri' => $this->normalizedString($resourceRight->scheme_uri),
                    'source' => 'raw',
                ];
            })
            ->filter()
            ->all());
    }

    private function firstNonEmptyString(?string ...$values): ?string
    {
        foreach ($values as $value) {
            $normalized = $this->normalizedString($value);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizedString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function sanitizeLandingPageHtml(?string $html, DescriptionFormattingService $descriptionFormattingService): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $sanitizedHtml = $descriptionFormattingService->sanitizeHtml($html);

        return $sanitizedHtml !== '' ? $sanitizedHtml : null;
    }
}
