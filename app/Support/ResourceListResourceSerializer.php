<?php

namespace App\Support;

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\Right;
use App\Models\Title;

final class ResourceListResourceSerializer
{
    /**
     * Serialize a Resource model to an array for resource list responses.
     *
     * @param  Resource  $resource  The resource to serialize (must have all required relationships loaded)
     * @return array<string, mixed>
     */
    public function serialize(Resource $resource): array
    {
        // In development, assert all required relations are loaded to detect N+1 queries
        if (app()->environment('local', 'testing')) {
            $this->assertRelationsLoaded($resource);
        }

        // Get first creator
        $firstCreator = $resource->creators->first();
        $firstCreatorData = null;

        if ($firstCreator) {
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

        // Determine publication status based on DOI and landing page status
        $publicStatus = 'curation'; // Default status
        if ($resource->doi && $resource->landingPage) {
            $publicStatus = $resource->landingPage->is_published ? 'published' : 'review';
        }

        // Get DataCite dates (Created/Updated) from the dates relation instead of Eloquent timestamps.
        // This preserves the original creation/update dates from imported datasets.
        $createdDateRecord = $resource->dates->first(fn ($date) => $date->dateType->slug === 'Created');
        $createdDate = $createdDateRecord !== null
            ? ($createdDateRecord->date_value ?? $createdDateRecord->start_date)
            : null;

        // For Updated dates, get the most recent one by sorting only Updated dates
        $updatedDateRecord = $resource->dates
            ->filter(fn ($date) => $date->dateType->slug === 'Updated')
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
            // Use DataCite dates if available, otherwise fall back to Eloquent timestamps
            'created_at' => $createdDate ?? $resource->created_at?->toIso8601String(),
            'updated_at' => $updatedDate ?? $resource->updated_at?->toIso8601String(),
            'curator' => $resource->updatedBy?->name ?? $resource->createdBy?->name, // @phpstan-ignore nullsafe.neverNull (updatedBy can be null if updated_by_user_id is null)
            'publicstatus' => $publicStatus,
            'resourcetypegeneral' => $resource->resourceType?->name,
            'resource_type' => $resource->resourceType ? [
                'name' => $resource->resourceType->name,
                'slug' => $resource->resourceType->slug,
            ] : null,
            'language' => $resource->language ? [
                'code' => $resource->language->code,
                'name' => $resource->language->name,
            ] : null,
            'title' => $resource->titles->first()?->value,
            'titles' => $resource->titles
                ->map(static function (Title $title): array {
                    return [
                        'title' => $title->value,
                        // Use null-safe operator for legacy data where titleType may be null
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
            'landingPage' => $resource->landingPage ? [
                'id' => $resource->landingPage->id,
                'is_published' => $resource->landingPage->is_published,
                'public_url' => $resource->landingPage->public_url,
            ] : null,
        ];
    }

    /**
     * Assert that all required relations are loaded to prevent N+1 queries.
     *
     * This method is only called in development environment and throws an
     * exception if any required relation is not eager loaded.
     */
    private function assertRelationsLoaded(Resource $resource): void
    {
        $requiredRelations = [
            'creators',
            'contributors',
            'titles',
            'rights',
            'dates',
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
                    'Ensure ResourceListQuery::baseQuery() eager loads all required relationships.'
                );
            }
        }

        // Check that dateType is loaded on dates to prevent N+1 when accessing $date->dateType->slug
        if ($resource->dates->isNotEmpty()) {
            $firstDate = $resource->dates->first();
            if (! $firstDate->relationLoaded('dateType')) {
                throw new \RuntimeException(
                    'Relation dateType not loaded on ResourceDate. N+1 query detected!'
                );
            }
        }

        // Check nested relations on creators if creators exist
        if ($resource->creators->isNotEmpty()) {
            $firstCreator = $resource->creators->first();
            if (! $firstCreator->relationLoaded('creatorable')) {
                throw new \RuntimeException(
                    'Relation creatorable not loaded on ResourceCreator. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstCreator->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceCreator. N+1 query detected!'
                );
            }
        }

        // Check nested relations on contributors if contributors exist
        if ($resource->contributors->isNotEmpty()) {
            $firstContributor = $resource->contributors->first();
            if (! $firstContributor->relationLoaded('contributorable')) {
                throw new \RuntimeException(
                    'Relation contributorable not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            if (! $firstContributor->relationLoaded('contributorType')) {
                throw new \RuntimeException(
                    'Relation contributorType not loaded on ResourceContributor. N+1 query detected!'
                );
            }
            // Also check affiliations relation (note: affiliations are polymorphic and store
            // affiliation data directly - they don't have a separate institution relation)
            if (! $firstContributor->relationLoaded('affiliations')) {
                throw new \RuntimeException(
                    'Relation affiliations not loaded on ResourceContributor. N+1 query detected!'
                );
            }
        }
    }
}
