<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IgsnRegistrationRunStatus;
use App\Models\IgsnRegistrationItem;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class IgsnRegistrationExclusionService
{
    /**
     * The lock must outlive the 90-second queue-job timeout so a replacement
     * worker cannot start while the timed-out worker is still being stopped.
     */
    public const LOCK_TTL_SECONDS = 120;

    public function resourceLock(int $resourceId): Lock
    {
        return Cache::lock("igsn:registration:resource:{$resourceId}", self::LOCK_TTL_SECONDS);
    }

    public function hasActiveRun(int $resourceId): bool
    {
        return IgsnRegistrationItem::query()
            ->where('resource_id', $resourceId)
            ->whereHas('run', fn ($query) => $query->whereIn('status', IgsnRegistrationRunStatus::activeValues()))
            ->exists();
    }
}
