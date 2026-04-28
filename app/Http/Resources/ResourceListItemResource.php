<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\Title;
use App\Models\Right;
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

        // Get DataCite Created/Updated dates from the dates relation, falling back
        // to Eloquent timestamps. Filtering is done in-memory on the eager-loaded
        // collection (typically <10 dates per resource).
        $createdDateRecord = $resource->dates->first(
            fn ($date): bool => $date->dateType->slug === 'Created'
        );
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

        return [
            'id' => $resource->id,
            'doi' => $resource->doi,
            'year' => $resource->publication_year,
            'version' => $resource->version,
            'created_at' => $createdDate ?? $resource->created_at?->toIso8601String(),
            'updated_at' => $updatedDate ?? $resource->updated_at?->toIso8601String(),
            // @phpstan-ignore nullsafe.neverNull (updatedBy can be null if updated_by_user_id is null)
            'curator' => $resource->updatedBy?->name ?? $resource->createdBy?->name,
            'publicstatus' => $resource->publicStatus(),
            'resourcetypegeneral' => $resource->resourceType?->name,
            'resource_type' => $resource->resourceType !== null ? [
                'name' => $resource->resourceType->name,
                'slug' => $resource->resourceType->slug,
            ] : null,
            'language' => $resource->language !== null ? [
                'code' => $resource->language->code,
                'name' => $resource->language->name,
            ] : null,
            'title' => $resource->titles->first()?->value,
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
        $requiredRelations = [
            'creators',
            'contributors',
            'titles',
            'rights',
            'dates',
            'descriptions',
            'resourceType',
            'language',
            'createdBy',
            'updatedBy',
            'landingPage',
        ];

        foreach ($requiredRelations as $relation) {
            if (! $resource->relationLoaded($relation)) {
                throw new \RuntimeException(
                    "Relation '{$relation}' not loaded on Resource #{$resource->id}. N+1 query detected! ".
                    'Ensure baseQuery() eager loads all required relationships.'
                );
            }
        }

        if ($resource->dates->isNotEmpty()) {
            $firstDate = $resource->dates->first();
            if (! $firstDate->relationLoaded('dateType')) {
                throw new \RuntimeException(
                    'Relation dateType not loaded on ResourceDate. N+1 query detected!'
                );
            }
        }

        if ($resource->descriptions->isNotEmpty()) {
            $firstDescription = $resource->descriptions->first();
            if (! $firstDescription->relationLoaded('descriptionType')) {
                throw new \RuntimeException(
                    'Relation descriptionType not loaded on Description. N+1 query detected!'
                );
            }
        }

        if ($resource->creators->isNotEmpty()) {
            $firstCreator = $resource->creators->first();
            if (! $firstCreator->relationLoaded('creatorable')) {
                throw new \RuntimeException(
                    'Relation creatorable not loaded on ResourceCreator. N+1 query detected!'
                );
            }
            if (! $firstCreator->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceCreator. N+1 query detected!'
                );
            }
        }

        if ($resource->contributors->isNotEmpty()) {
            $firstContributor = $resource->contributors->first();
            if (! $firstContributor->relationLoaded('contributorable')) {
                throw new \RuntimeException(
                    'Relation contributorable not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            if (! $firstContributor->relationLoaded('contributorTypes')) {
                throw new \RuntimeException(
                    'Relation contributorTypes not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            if (! $firstContributor->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceContributor. N+1 query detected!'
                );
            }
        }
    }
}
