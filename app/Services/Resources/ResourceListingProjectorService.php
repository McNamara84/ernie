<?php

declare(strict_types=1);

namespace App\Services\Resources;

use App\Enums\ResourceWorkflowStatus;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Models\Right;
use App\Models\Title;
use App\Services\DashboardMetricsCacheInvalidationService;
use App\Services\Rights\CustomRightCatalogService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ResourceListingProjectorService
{
    private ?bool $tableExists = null;

    public function __construct(
        private readonly DashboardMetricsCacheInvalidationService $metricsCacheInvalidationService,
    ) {}

    public function refresh(int $resourceId): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $resource = $this->query()->find($resourceId);

        if ($resource === null) {
            ResourceListingProjection::query()->whereKey($resourceId)->delete();

            return;
        }

        ResourceListingProjection::query()->updateOrCreate(
            ['resource_id' => $resourceId],
            $this->values($resource),
        );
        $this->metricsCacheInvalidationService->scheduleAfterCommit();
    }

    /** @param iterable<int> $resourceIds */
    public function refreshMany(iterable $resourceIds): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $ids = collect($resourceIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $ids->chunk(500)->each(function ($chunk): void {
            /** @var Collection<int, Resource> $resources */
            $resources = $this->query()->whereKey($chunk->all())->get();
            $this->upsert($resources);

            $foundIds = array_fill_keys(array_map(
                static fn (int|string $id): int => (int) $id,
                $resources->modelKeys(),
            ), true);
            $missingIds = $chunk
                ->filter(static fn (int $id): bool => ! isset($foundIds[$id]))
                ->all();
            if ($missingIds !== []) {
                ResourceListingProjection::query()->whereKey($missingIds)->delete();
            }
        });
    }

    public function forget(int $resourceId): void
    {
        if ($this->tableExists()) {
            ResourceListingProjection::query()->whereKey($resourceId)->delete();
        }
    }

    public function rebuildAll(): void
    {
        if (! $this->tableExists()) {
            return;
        }

        $this->query()->chunkById(500, function (Collection $resources): void {
            $this->upsert($resources);
        });
    }

    /** @return Builder<Resource> */
    private function query(): Builder
    {
        return Resource::query()->with([
            'resourceType:id,name,slug',
            'createdBy:id,name',
            'updatedBy:id,name',
            'landingPage:id,resource_id,is_published,published_at',
            'titles' => fn ($query) => $query
                ->select(['id', 'resource_id', 'value', 'title_type_id'])
                ->with('titleType:id,slug')
                ->orderBy('id'),
            'rights:id,scheme_uri',
            'descriptions' => fn ($query) => $query
                ->select(['id', 'resource_id', 'value', 'description_type_id'])
                ->with('descriptionType:id,slug'),
            'dates' => fn ($query) => $query
                ->select(['id', 'resource_id', 'date_type_id', 'date_value', 'start_date'])
                ->with('dateType:id,slug'),
            'creators' => fn ($query) => $query->with('creatorable')->orderBy('position')->orderBy('id'),
        ]);
    }

    /** @param Collection<int, Resource> $resources */
    private function upsert(Collection $resources): void
    {
        if ($resources->isEmpty()) {
            return;
        }

        /** @var Resource $firstResource */
        $firstResource = $resources->first();

        $now = now();
        $rows = $resources->map(fn (Resource $resource): array => [
            'resource_id' => $resource->id,
            ...$this->values($resource),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        ResourceListingProjection::query()->upsert(
            $rows,
            ['resource_id'],
            array_keys($this->values($firstResource)),
        );
        $this->metricsCacheInvalidationService->scheduleAfterCommit();
    }

    /** @return array<string, mixed> */
    private function values(Resource $resource): array
    {
        $mainTitle = ($resource->titles->first(fn (Title $title): bool => $title->isMainTitle())
            ?? $resource->titles->first())->value ?? '';
        $firstCreator = $resource->creators->first()?->creatorable;
        $firstCreatorSort = match (true) {
            $firstCreator instanceof Person => $firstCreator->family_name ?? $firstCreator->given_name ?? '',
            $firstCreator instanceof Institution => $firstCreator->name,
            default => '',
        };
        $createdDate = $resource->dates
            ->filter(fn ($date): bool => $date->dateType->slug === 'Created')
            ->map(fn ($date): string => (string) ($date->date_value ?? $date->start_date ?? ''))
            ->filter()
            ->sort()
            ->first();
        $updatedDate = $resource->dates
            ->filter(fn ($date): bool => $date->dateType->slug === 'Updated')
            ->map(fn ($date): string => (string) ($date->date_value ?? $date->start_date ?? ''))
            ->filter()
            ->sortDesc()
            ->first();
        $status = $resource->publicStatus();
        $curator = $resource->updatedBy ?? $resource->createdBy;
        $resourceType = $resource->resourceType;

        return [
            'is_igsn' => $resourceType?->slug === 'physical-object',
            'has_spdx_license' => $resource->rights->contains(
                static fn (Right $right): bool => CustomRightCatalogService::isSpdxRight($right),
            ),
            'workflow_status' => $status,
            'workflow_status_rank' => match ($status) {
                ResourceWorkflowStatus::DRAFT->value => 0,
                'curation' => 1,
                ResourceWorkflowStatus::REVIEW->value => 2,
                'published' => 3,
                default => 0,
            },
            'is_dashboard_draft' => $status === ResourceWorkflowStatus::DRAFT->value,
            'resource_type_id' => $resource->resource_type_id,
            'resource_type_slug' => $resourceType?->slug,
            'resource_type_sort' => $resourceType === null ? '' : $resourceType->name,
            'datacenter_id' => $resource->datacenter_id,
            'curator_user_id' => $curator?->id,
            'curator_name' => $curator === null ? '' : $curator->name,
            'publication_year' => $resource->publication_year,
            'sort_year' => $resource->publication_year ?? 0,
            'sort_doi' => $resource->doi ?? '',
            'main_title' => $mainTitle,
            'main_title_sort' => mb_substr($mainTitle, 0, 512),
            'first_creator_sort' => $firstCreatorSort,
            'created_sort' => $createdDate ?: $resource->created_at?->toIso8601String() ?? '',
            'updated_sort' => $updatedDate ?: $resource->updated_at?->toIso8601String() ?? '',
            'search_text' => mb_strtolower(implode("\n", array_filter([
                $resource->doi,
                ...$resource->titles->pluck('value')->all(),
            ]))),
        ];
    }

    private function tableExists(): bool
    {
        if ($this->tableExists === true) {
            return true;
        }

        $databaseManager = DB::getFacadeRoot();
        if (! $databaseManager instanceof DatabaseManager || str_starts_with($databaseManager::class, 'Mockery_')) {
            return false;
        }

        try {
            return $this->tableExists = Schema::hasTable('resource_listing_projections');
        } catch (Throwable) {
            // Some isolated service tests replace the database manager and some
            // commands run while the schema is unavailable. Source writes must
            // still succeed; a later rebuild can restore the derived projection.
            return false;
        }
    }
}
