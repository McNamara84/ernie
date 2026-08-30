<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\Right;
use App\Models\Title;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Resource $resource
 *
 * Single-row representation of a Resource for list views (Resources index, IGSNs index).
 *
 * Output shape is contract-stable: any change must be mirrored in the frontend
 * types under resources/js/types/resources.ts and the OpenAPI spec.
 */
final class ResourceListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Resource $resource */
        $resource = $this->resource;
        $canSendReviewLinks = $request->user()?->can('send-review-links') === true;
        $usesListingProjection = array_key_exists('listing_workflow_status', $resource->getAttributes());

        if (app()->environment('local', 'testing')) {
            self::assertRelationsLoaded($resource);
        }

        $firstCreator = $resource->creators->first();
        $firstCreatorData = null;

        if ($firstCreator !== null) {
            $creatorable = $firstCreator->creatorable;
            if ($creatorable instanceof Person) {
                $firstCreatorData = [
                    'givenName' => $creatorable->given_name,
                    'familyName' => $creatorable->family_name,
                ];
            } elseif ($creatorable instanceof Institution) {
                $firstCreatorData = [
                    'name' => $creatorable->name,
                ];
            }
        }

        if ($usesListingProjection) {
            $createdDate = $resource->getAttribute('listing_created_sort');
            $updatedDate = $resource->getAttribute('listing_updated_sort');
            $curator = $resource->getAttribute('listing_curator_name') ?: null;
            $publicStatus = $resource->getAttribute('listing_workflow_status');
            $resourceTypeName = $resource->getAttribute('listing_resource_type_sort') ?: null;
            $resourceTypeSlug = $resource->getAttribute('listing_resource_type_slug') ?: null;
            $mainTitle = $resource->getAttribute('listing_main_title') ?: null;
        } else {
            // Keep the transformer reusable for IGSN and other non-projected
            // call sites while the internal resource list avoids these relations.
            $createdDateRecord = $resource->dates
                ->filter(fn ($date): bool => $date->dateType->slug === 'Created')
                ->sortBy(fn ($date) => $date->date_value ?? $date->start_date ?? '')
                ->first();
            $createdDate = $createdDateRecord !== null
                ? ($createdDateRecord->date_value ?? $createdDateRecord->start_date)
                : null;

            $updatedDateRecord = $resource->dates
                ->filter(fn ($date): bool => $date->dateType->slug === 'Updated')
                ->sortByDesc(fn ($date) => $date->date_value ?? $date->start_date ?? '')
                ->first();
            $updatedDate = $updatedDateRecord !== null
                ? ($updatedDateRecord->date_value ?? $updatedDateRecord->start_date)
                : null;
            // @phpstan-ignore nullsafe.neverNull (updatedBy is nullable in the database)
            $curator = $resource->updatedBy?->name ?? $resource->createdBy?->name;
            $publicStatus = $resource->publicStatus();
            $resourceTypeName = $resource->resourceType?->name;
            $resourceTypeSlug = $resource->resourceType?->slug;
            $mainTitle = null;
        }

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'year' => $resource->publication_year,
            'version' => $resource->version,
            'created_at' => $createdDate ?? $resource->created_at?->toIso8601String(),
            'updated_at' => $updatedDate ?? $resource->updated_at?->toIso8601String(),
            'curator' => $curator,
            'publicstatus' => $publicStatus,
            'resourcetypegeneral' => $resourceTypeName,
            'resource_type' => $resourceTypeName !== null ? [
                'name' => $resourceTypeName,
                'slug' => $resourceTypeSlug,
            ] : null,
            'language' => $resource->language !== null ? [
                'code' => $resource->language->code,
                'name' => $resource->language->name,
            ] : null,
            // Pick the main title explicitly (titles are eager-loaded ordered by id
            // and may include subtitles / alternate titles, so `titles->first()`
            // could surface the wrong title in list views). Falls back to the
            // first title if no MainTitle is flagged.
            'title' => $mainTitle ?? ($resource->titles->first(fn (Title $title): bool => $title->isMainTitle())
                ?? $resource->titles->first())?->value,
            'titles' => $resource->titles
                ->map(static function (Title $title): array {
                    return [
                        'title' => $title->value,
                        'title_type' => $title->titleType !== null ? [
                            'name' => $title->titleType->name,
                            'slug' => $title->titleType->slug,
                        ] : [
                            'name' => 'Main Title',
                            'slug' => 'MainTitle',
                        ],
                    ];
                })
                ->values()
                ->all(),
            'rights' => $resource->rights
                ->map(static function (Right $right): array {
                    return [
                        'identifier' => $right->identifier,
                        'name' => $right->name,
                    ];
                })
                ->values()
                ->all(),
            'first_author' => $firstCreatorData,
            'landingPage' => $resource->landingPage !== null ? [
                'id' => $resource->landingPage->id,
                'is_published' => $resource->landingPage->is_published,
                'public_url' => $resource->landingPage->public_url,
                'preview_url' => $this->when(
                    $canSendReviewLinks,
                    fn (): ?string => $resource->landingPage->preview_url,
                ),
            ] : null,
        ];
    }

    /**
     * Assert that all required relations are loaded (dev/testing only) to
     * surface N+1 query regressions early.
     *
     * @throws \RuntimeException if a required relation is not loaded
     */
    private static function assertRelationsLoaded(Resource $resource): void
    {
        // Only relations actually consumed by toArray() (and the `publicStatus()`
        // / `isComplete()` helpers it calls) are required here. Adding relations
        // that the list-item contract does not surface inflates query count and
        // memory for every list endpoint without benefit.
        $requiredRelations = [
            'creators',
            'titles',
            'rights',
            'language',
            'landingPage',
        ];

        if (! array_key_exists('listing_workflow_status', $resource->getAttributes())) {
            $requiredRelations = [
                ...$requiredRelations,
                'dates',
                'descriptions',
                'resourceType',
                'createdBy',
                'updatedBy',
            ];
        }

        foreach ($requiredRelations as $relation) {
            if (! $resource->relationLoaded($relation)) {
                throw new \RuntimeException(
                    "Relation '{$relation}' not loaded on Resource #{$resource->id}. N+1 query detected! ".
                    'Ensure baseQuery() eager loads all required relationships.'
                );
            }
        }

        if ($resource->relationLoaded('dates') && $resource->dates->isNotEmpty()) {
            $firstDate = $resource->dates->first();
            if (! $firstDate->relationLoaded('dateType')) {
                throw new \RuntimeException(
                    'Relation dateType not loaded on ResourceDate. N+1 query detected!'
                );
            }
        }

        if ($resource->relationLoaded('descriptions') && $resource->descriptions->isNotEmpty()) {
            $firstDescription = $resource->descriptions->first();
            if (! $firstDescription->relationLoaded('descriptionType')) {
                throw new \RuntimeException(
                    'Relation descriptionType not loaded on Description. N+1 query detected!'
                );
            }
        }

        // titles.titleType is required because Title::isMainTitle() reads it
        // (and we use that to pick the main title for the `title` field).
        if ($resource->titles->isNotEmpty()) {
            $firstTitle = $resource->titles->first();
            if (! $firstTitle->relationLoaded('titleType')) {
                throw new \RuntimeException(
                    'Relation titleType not loaded on Title. N+1 query detected!'
                );
            }
        }

        // landingPage.externalDomain is required because LandingPage::public_url
        // reads it for external landing pages (template === 'external').
        if ($resource->landingPage !== null && ! $resource->landingPage->relationLoaded('externalDomain')) {
            throw new \RuntimeException(
                'Relation externalDomain not loaded on LandingPage. N+1 query detected!'
            );
        }

        if ($resource->creators->isNotEmpty()) {
            $firstCreator = $resource->creators->first();
            if (! $firstCreator->relationLoaded('creatorable')) {
                throw new \RuntimeException(
                    'Relation creatorable not loaded on ResourceCreator. N+1 query detected!'
                );
            }
            // Note: `creators.affiliations` is intentionally NOT required here.
            // toArray() only surfaces the first creator's name (Person /
            // Institution), not its affiliations, and `publicStatus()` /
            // `isComplete()` only check `creators->isEmpty()`. Eager-loading
            // affiliations for every list-item row would inflate query count
            // and memory without affecting the output contract.
        }
    }
}
