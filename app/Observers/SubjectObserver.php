<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Subject;
use App\Services\PortalKeywordCacheInvalidationService;

class SubjectObserver
{
    public function __construct(
        private readonly PortalKeywordCacheInvalidationService $cacheInvalidationService,
    ) {}

    public function saved(Subject $subject): void
    {
        $this->cacheInvalidationService->scheduleAfterCommit();
    }

    public function deleted(Subject $subject): void
    {
        $this->cacheInvalidationService->scheduleAfterCommit();
    }
}