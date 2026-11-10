<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Services\DcatAp\DcatApRecordService;
use Illuminate\Database\Eloquent\Model;

final class DcatApMetadataObserver
{
    public function saved(Model $model): void
    {
        if ($model instanceof LandingPage && ! $model->wasRecentlyCreated
            && array_diff(array_keys($model->getChanges()), ['updated_at', 'view_count', 'last_viewed_at']) === []) {
            return;
        }
        $this->schedule($model);
    }

    public function deleted(Model $model): void
    {
        $this->schedule($model);
    }

    private function schedule(Model $model): void
    {
        if (! config('dcatap.enabled', false)) {
            return;
        }
        $resourceId = $model instanceof Resource ? $model->getKey() : $model->getAttribute('resource_id');
        if ($resourceId === null && $model->getAttribute('landing_page_id') !== null) {
            $resourceId = LandingPage::whereKey($model->getAttribute('landing_page_id'))->value('resource_id');
        }
        if ($resourceId === null && $model->getAttribute('resource_contributor_id') !== null) {
            $resourceId = ResourceContributor::whereKey($model->getAttribute('resource_contributor_id'))->value('resource_id');
        }
        if (is_numeric($resourceId)) {
            app(DcatApRecordService::class)->schedule((int) $resourceId, ! $model instanceof Resource);
        }
    }
}
