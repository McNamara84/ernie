<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Affiliation;
use App\Models\Datacenter;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Assistance\AssistanceDatacenterOptionsCacheInvalidationService;
use Illuminate\Database\Eloquent\Model;

/** Invalidates Assistance Datacenter options when an impact dependency changes. */
final class AssistanceDatacenterOptionsObserver
{
    public function __construct(
        private readonly AssistanceDatacenterOptionsCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function created(Model $model): void
    {
        $this->forget();
    }

    public function updated(Model $model): void
    {
        if ($this->updatedChangeAffectsOptions($model)) {
            $this->forget();
        }
    }

    public function deleted(Model $model): void
    {
        $this->forget();
    }

    private function updatedChangeAffectsOptions(Model $model): bool
    {
        return match (true) {
            $model instanceof Affiliation => $model->wasChanged([
                'affiliatable_type',
                'affiliatable_id',
            ]),
            $model instanceof ResourceCreator => $model->wasChanged([
                'resource_id',
                'creatorable_type',
                'creatorable_id',
            ]),
            $model instanceof ResourceContributor => $model->wasChanged([
                'resource_id',
                'contributorable_type',
                'contributorable_id',
            ]),
            $model instanceof Datacenter => $model->wasChanged('name'),
            default => false,
        };
    }

    private function forget(): void
    {
        $this->cacheInvalidationService->scheduleAfterCommit();
    }
}
