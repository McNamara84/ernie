<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\ResourceType;
use App\Services\ResourceCacheService;

class ResourceTypeObserver
{
    public function __construct(
        private readonly ResourceCacheService $cacheService,
    ) {}

    public function saved(ResourceType $resourceType): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }

    public function deleted(ResourceType $resourceType): void
    {
        $this->cacheService->invalidateAllResourceCaches();
    }
}