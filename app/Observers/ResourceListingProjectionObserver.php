<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceContributorTypePivot;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Eloquent\Model;

final class ResourceListingProjectionObserver
{
    public function __construct(private readonly ResourceListingProjectionRefreshService $scheduler) {}

    public function saved(Model $model): void
    {
        $resourceId = $this->resourceId($model);

        if (is_numeric($resourceId)) {
            $this->scheduler->schedule((int) $resourceId);
        }
    }

    public function deleted(Model $model): void
    {
        $resourceId = $this->resourceId($model);

        if (! is_numeric($resourceId)) {
            return;
        }

        if ($model instanceof Resource) {
            $this->scheduler->forget((int) $resourceId);

            return;
        }

        $this->scheduler->schedule((int) $resourceId);
    }

    private function resourceId(Model $model): mixed
    {
        if ($model instanceof ResourceContributorTypePivot) {
            return ResourceContributor::query()->whereKey($model->getAttribute('resource_contributor_id'))->value('resource_id');
        }

        return $model instanceof Resource ? $model->id : $model->getAttribute('resource_id');
    }
}
