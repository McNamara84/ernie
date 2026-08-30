<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\CacheKey;
use App\Models\Datacenter;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceListingProjection;
use App\Models\ResourceRight;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class RefreshResourceListingProjectionsForDependencyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const EVENT_UPDATED = 'updated';

    public const EVENT_DELETED = 'deleted';

    public const BATCH_SIZE = 500;

    /** @var list<class-string> */
    private const SUPPORTED_DEPENDENCIES = [
        ResourceType::class,
        Datacenter::class,
        Right::class,
        User::class,
        Person::class,
        Institution::class,
        TitleType::class,
        DescriptionType::class,
        DateType::class,
    ];

    public int $tries = 3;

    public int $timeout = 120;

    /** @param class-string $dependencyType */
    public function __construct(
        public readonly string $dependencyType,
        public readonly int $dependencyId,
        public readonly string $event,
        public readonly int $afterResourceId = 0,
    ) {
        if (! in_array($dependencyType, self::SUPPORTED_DEPENDENCIES, true)) {
            throw new InvalidArgumentException('Unsupported resource listing projection dependency.');
        }

        if (! in_array($event, [self::EVENT_UPDATED, self::EVENT_DELETED], true)) {
            throw new InvalidArgumentException('Unsupported resource listing projection dependency event.');
        }
    }

    public function handle(
        ResourceListingProjectionRefreshService $scheduler,
        ResourceCacheService $resourceCacheService,
        ListingCountService $listingCountService,
    ): void {
        if (! Schema::hasTable('resource_listing_projections')) {
            return;
        }

        if ($this->event === self::EVENT_UPDATED && $this->updateProjectedLookupValues(
            $resourceCacheService,
            $listingCountService,
        )) {
            return;
        }

        $resourceIds = $this->nextResourceIds();
        if ($resourceIds->isEmpty()) {
            return;
        }

        $scheduler->scheduleMany($resourceIds);

        if ($resourceIds->count() < self::BATCH_SIZE) {
            return;
        }

        self::dispatch(
            $this->dependencyType,
            $this->dependencyId,
            $this->event,
            (int) $resourceIds->last(),
        )->afterCommit();
    }

    private function updateProjectedLookupValues(
        ResourceCacheService $resourceCacheService,
        ListingCountService $listingCountService,
    ): bool {
        $updatedRows = match ($this->dependencyType) {
            ResourceType::class => $this->updateResourceTypeProjectionValues(),
            User::class => $this->updateCuratorProjectionValues(),
            default => null,
        };

        if ($updatedRows === null) {
            return false;
        }

        if ($updatedRows > 0) {
            CacheKey::DASHBOARD_METRICS->forget();
            $resourceCacheService->invalidateAllResourceCaches();
            $listingCountService->scheduleInternalInvalidationAfterCommit();
        }

        return true;
    }

    private function updateResourceTypeProjectionValues(): int
    {
        $resourceType = ResourceType::query()->find($this->dependencyId);
        if ($resourceType === null) {
            return 0;
        }

        return ResourceListingProjection::query()
            ->where('resource_type_id', $resourceType->id)
            ->update([
                'is_igsn' => $resourceType->slug === 'physical-object',
                'resource_type_slug' => $resourceType->slug,
                'resource_type_sort' => $resourceType->name,
                'updated_at' => now(),
            ]);
    }

    private function updateCuratorProjectionValues(): int
    {
        $user = User::query()->find($this->dependencyId);
        if ($user === null) {
            return 0;
        }

        return ResourceListingProjection::query()
            ->where('curator_user_id', $user->id)
            ->update([
                'curator_name' => $user->name,
                'updated_at' => now(),
            ]);
    }

    /** @return Collection<int, int> */
    private function nextResourceIds(): Collection
    {
        $ids = match ($this->dependencyType) {
            ResourceType::class => ResourceListingProjection::query()
                ->where('resource_type_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            Datacenter::class => ResourceListingProjection::query()
                ->where('datacenter_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            User::class => ResourceListingProjection::query()
                ->where('curator_user_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            Person::class, Institution::class => ResourceCreator::query()
                ->where('creatorable_type', $this->dependencyType)
                ->where('creatorable_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->distinct()
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            TitleType::class => Title::query()
                ->where('title_type_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->distinct()
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            DescriptionType::class => Description::query()
                ->where('description_type_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->distinct()
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            DateType::class => ResourceDate::query()
                ->where('date_type_id', $this->dependencyId)
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->distinct()
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            Right::class => ResourceRight::query()
                ->when(
                    $this->event === self::EVENT_DELETED,
                    fn ($query) => $query->whereNull('rights_id'),
                    fn ($query) => $query->where('rights_id', $this->dependencyId),
                )
                ->where('resource_id', '>', $this->afterResourceId)
                ->orderBy('resource_id')
                ->distinct()
                ->limit(self::BATCH_SIZE)
                ->pluck('resource_id'),
            default => collect(),
        };

        return $ids
            ->map(static fn (mixed $resourceId): int => (int) $resourceId)
            ->values();
    }
}
