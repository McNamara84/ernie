<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Resource;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Database\Eloquent\Model;

final class ResourceListingProjectionObserver
{
    public function __construct(private readonly ResourceListingProjectionRefreshService $scheduler) {}

    public function saved(Model $model): void
    {
        $resourceId = $model instanceof Resource ? $model->id : $model->getAttribute('resource_id');

        if (is_numeric($resourceId)) {
            $this->scheduler->schedule((int) $resourceId);
        }
    }

    public function deleted(Model $model): void
    {
        $resourceId = $model instanceof Resource ? $model->id : $model->getAttribute('resource_id');

        if (! is_numeric($resourceId)) {
            return;
        }

        if ($model instanceof Resource) {
            $this->scheduler->forget((int) $resourceId);

            return;
        }

        $this->scheduler->schedule((int) $resourceId);
    }
}
