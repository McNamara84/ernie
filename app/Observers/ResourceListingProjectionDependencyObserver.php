<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\Datacenter;
use App\Models\DateType;
use App\Models\DescriptionType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\TitleType;
use App\Models\User;
use App\Services\Resources\ResourceFilterOptionsCacheInvalidationService;
use Illuminate\Database\Eloquent\Model;

/** Refresh projections when a denormalized lookup or display name changes. */
final class ResourceListingProjectionDependencyObserver
{
    public function __construct(
        private readonly ResourceFilterOptionsCacheInvalidationService $filterOptionsCacheInvalidationService,
    ) {}

    public function updated(Model $model): void
    {
        if ($model instanceof Datacenter) {
            if ($model->wasChanged('name')) {
                $this->filterOptionsCacheInvalidationService->scheduleAfterCommit();
            }

            return;
        }

        if (! $this->updatedChangeAffectsProjection($model)) {
            return;
        }

        $this->dispatch($model, RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED);
    }

    public function deleted(Model $model): void
    {
        $this->dispatch($model, RefreshResourceListingProjectionsForDependencyJob::EVENT_DELETED);
    }

    private function updatedChangeAffectsProjection(Model $model): bool
    {
        return match (true) {
            $model instanceof ResourceType => $model->wasChanged(['name', 'slug']),
            $model instanceof User => $model->wasChanged('name'),
            $model instanceof Person => $model->wasChanged(['family_name', 'given_name']),
            $model instanceof Institution => $model->wasChanged('name'),
            $model instanceof TitleType,
            $model instanceof DescriptionType,
            $model instanceof DateType => $model->wasChanged('slug'),
            // Catalog Right attributes are not denormalized into this projection,
            // so their saves require no work. Datacenters are handled above because
            // only their cached filter label changes when they are renamed.
            $model instanceof Right => false,
            default => false,
        };
    }

    private function dispatch(Model $model, string $event): void
    {
        $dependencyId = $model->getKey();
        if (! is_numeric($dependencyId)) {
            return;
        }

        RefreshResourceListingProjectionsForDependencyJob::dispatch(
            $model::class,
            (int) $dependencyId,
            $event,
        )->afterCommit();
    }
}
