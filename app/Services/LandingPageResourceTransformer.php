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
use App\Models\Right;
use App\Models\Title;
use Illuminate\Database\Eloquent\Collection;

final class LandingPageResourceTransformer
{
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
            'rights',
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
            'igsnMetadata.parentResource.landingPage',
            'igsnClassifications',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transform(Resource $resource): array
    {
        $resourceData = $resource->toArray();

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
                    'identifier_type' => $identifierType !== null ? $identifierType->name : null,
                    'relation_type' => $relationType !== null ? $relationType->name : null,
                    'position' => $relatedId->position,
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

        $resourceData['descriptions'] = $resource->descriptions
            ->map(static function (Description $desc): array {
                /** @var DescriptionType|null $descriptionType */
                $descriptionType = $desc->descriptionType;

                return [
                    'id' => $desc->id,
                    'value' => $desc->value,
                    'description_type' => $descriptionType !== null ? $descriptionType->name : null,
                ];
            })
            ->all();

        $resourceData['creators'] = $resource->creators
            ->map(static function (ResourceCreator $creator): array {
                /** @var Person|Institution|null $creatorable */
                $creatorable = $creator->creatorable;

                return [
                    'id' => $creator->id,
                    'position' => $creator->position,
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
            ->map(static function (ResourceContributor $contributor): array {
                /** @var Person|Institution|null $contributorable */
                $contributorable = $contributor->contributorable;

                return [
                    'id' => $contributor->id,
                    'position' => $contributor->position,
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
            ->map(static fn ($subject): array => [
                'id' => $subject->id,
                'subject' => $subject->value,
                'subject_scheme' => $subject->subject_scheme,
                'scheme_uri' => $subject->scheme_uri,
                'value_uri' => $subject->value_uri,
                'classification_code' => $subject->classification_code,
            ])
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
            ])
            ->all();

        // Transform rights to licenses with frontend-compatible field names
        $resourceData['licenses'] = $resource->rights
            ->map(static fn (Right $right): array => [
                'id' => $right->id,
                'name' => $right->name,
                'spdx_id' => $right->identifier,
                'reference' => $right->uri,
            ])
            ->all();

        // 1. Collect creator contact persons (is_contact flag + has email)
        $creatorContactPersons = $resource->creators
            ->filter(static fn (ResourceCreator $creator): bool => $creator->is_contact && $creator->email !== null && $creator->email !== '')
            ->sortBy('position')
            ->values();

        // 2. Track creator entity IDs for deduplication (type+id pairs)
        $creatorEntityKeys = $creatorContactPersons
            ->map(static fn (ResourceCreator $creator): string => $creator->creatorable_type.'|'.$creator->creatorable_id)
            ->all();

        // 3. Collect contributor contact persons (ContributorType "ContactPerson" + has email, deduplicated)
        $contributorContactPersons = $resource->contributors
            ->filter(static function (ResourceContributor $contributor) use ($creatorEntityKeys): bool {
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

                // Skip if same entity already exists as a creator contact person
                $entityKey = $contributor->contributorable_type.'|'.$contributor->contributorable_id;

                return ! in_array($entityKey, $creatorEntityKeys, true);
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
        ) use ($buildEntityName): array {
            $isPerson = $entity instanceof Person;
            $givenName = $isPerson ? $entity->given_name : null;
            $familyName = $isPerson ? $entity->family_name : null;
            $nameIdentifierScheme = $entity?->name_identifier_scheme;
            $nameIdentifier = $entity?->name_identifier;

            return [
                'id' => $id,
                'name' => $buildEntityName($entity),
                'given_name' => $givenName,
                'family_name' => $familyName,
                'type' => class_basename($morphType),
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
            ->map(static fn (ResourceContributor $contributor): array => $mapContactEntry(
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
            ));

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

            $resourceData['igsn_metadata'] = [
                'sample_type' => $meta->sample_type,
                'material' => $meta->material,
                'cruise_field_program' => $meta->cruise_field_program,
                'sample_purpose' => $meta->sample_purpose,
                'collection_method' => $meta->collection_method,
                'collection_method_description' => $meta->collection_method_description,
                'parent' => $parent === null ? null : [
                    'doi' => $parent->doi,
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
                ])
                ->all();
        } else {
            $resourceData['igsn_metadata'] = null;
            $resourceData['igsn_classifications'] = [];
        }

        return $resourceData;
    }
}
