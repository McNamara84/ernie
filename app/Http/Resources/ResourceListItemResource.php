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
        //
        // The `dates` relation is unordered and the schema permits multiple
        // `Created` / `Updated` rows per resource (e.g. when an XML import
        // carries both an explicit `Created` and historical revisions), so we
        // sort deterministically before picking one: earliest `Created` for
        // `created_at`, latest `Updated` for `updated_at`. Without this two
        // identical responses could surface different timestamps depending on
        // database row ordering.
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
            // Pick the main title explicitly (titles are eager-loaded ordered by id
            // and may include subtitles / alternate titles, so `titles->first()`
            // could surface the wrong title in list views). Falls back to the
            // first title if no MainTitle is flagged.
            'title' => ($resource->titles->first(fn (Title $title): bool => $title->isMainTitle())
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
