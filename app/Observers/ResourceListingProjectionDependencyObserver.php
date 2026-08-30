<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Datacenter;
use App\Models\DateType;
use App\Models\Description;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\ResourceRight;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Eloquent\Model;

/** Refresh projections when a denormalized lookup or display name changes. */
final class ResourceListingProjectionDependencyObserver
{
    private const DELETING_RESOURCE_IDS_RELATION = '__listingProjectionResourceIds';

    public function __construct(private readonly ResourceListingProjectionRefreshService $scheduler) {}

    public function saved(Model $model): void
    {
        $this->scheduler->scheduleMany($this->dependentResourceIds($model));
    }

    public function deleting(Model $model): void
    {
        // The same model instance is passed to the deleted event even when the
        // observer itself is resolved again. Keep IDs on that model because a
        // database cascade may remove the dependency rows before deleted fires.
        $model->setRelation(self::DELETING_RESOURCE_IDS_RELATION, $this->dependentResourceIds($model));
    }

    public function deleted(Model $model): void
    {
        $resourceIds = $model->getRelation(self::DELETING_RESOURCE_IDS_RELATION);
        $model->unsetRelation(self::DELETING_RESOURCE_IDS_RELATION);

        $this->scheduler->scheduleMany(is_array($resourceIds) ? $resourceIds : []);
    }

    /** @return array<int> */
    private function dependentResourceIds(Model $model): array
    {
        $ids = match (true) {
            $model instanceof ResourceType => Resource::query()
                ->where('resource_type_id', $model->id)
                ->pluck('id'),
            $model instanceof Datacenter => Resource::query()
                ->where('datacenter_id', $model->id)
                ->pluck('id'),
            $model instanceof User => Resource::query()
                ->where('created_by_user_id', $model->id)
                ->orWhere('updated_by_user_id', $model->id)
                ->pluck('id'),
            $model instanceof Person, $model instanceof Institution => ResourceCreator::query()
                ->where('creatorable_type', $model::class)
                ->where('creatorable_id', $model->id)
                ->pluck('resource_id'),
            $model instanceof TitleType => Title::query()
                ->where('title_type_id', $model->id)
                ->pluck('resource_id'),
            $model instanceof DescriptionType => Description::query()
                ->where('description_type_id', $model->id)
                ->pluck('resource_id'),
            $model instanceof DateType => ResourceDate::query()
                ->where('date_type_id', $model->id)
                ->pluck('resource_id'),
            $model instanceof Right => ResourceRight::query()
                ->where('rights_id', $model->id)
                ->pluck('resource_id'),
            default => [],
        };

        return collect($ids)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
