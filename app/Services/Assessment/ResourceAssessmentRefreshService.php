<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\AssessmentRunItemStatus;
use App\Jobs\RefreshPublishedResourceAssessmentJob;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceAssessmentRefresh;
use Illuminate\Support\Facades\DB;

final class ResourceAssessmentRefreshService
{
    public function __construct(private readonly AssessmentQueueService $queue) {}

    public function request(int $resourceId): void
    {
        if (! config('fuji.enabled') || ! $this->queue->isPersistent()
            || (! ResourceAssessment::query()->where('resource_id', $resourceId)->exists()
                && ! AssessmentRunItem::query()->where('resource_id', $resourceId)
                    ->where('status', AssessmentRunItemStatus::PROCESSING)->exists())) {
            return;
        }

        DB::transaction(function () use ($resourceId): void {
            // Serialize first-time requests as well as updates. A missing refresh
            // row cannot itself be locked by two concurrent publication events.
            $resource = Resource::query()->whereKey($resourceId)->lockForUpdate()->first();
            if ($resource === null || trim((string) $resource->doi) === '') {
                return;
            }

            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($resourceId);
            if ($refresh === null) {
                $refresh = ResourceAssessmentRefresh::query()->create([
                    'resource_id' => $resourceId,
                    'status' => ResourceAssessmentRefresh::PENDING,
                    'requested_at' => now(),
                    'available_at' => now(),
                ]);
            } else {
                $refresh->forceFill([
                    'status' => ResourceAssessmentRefresh::PENDING,
                    'generation' => $refresh->generation + 1,
                    'attempts' => 0,
                    'service_attempts' => 0,
                    'requested_at' => now(),
                    'available_at' => now(),
                    'lease_expires_at' => null,
                    'completed_at' => null,
                    'last_error' => null,
                ])->save();
            }

            $this->dispatch($resourceId);
        }, 3);
    }

    public function recover(): void
    {
        if (! config('fuji.enabled') || ! $this->queue->isPersistent()) {
            return;
        }

        ResourceAssessmentRefresh::query()
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->where('status', ResourceAssessmentRefresh::PENDING)
                        ->where('available_at', '<=', now());
                })->orWhere(function ($processing): void {
                    $processing->whereIn('status', [ResourceAssessmentRefresh::QUEUED, ResourceAssessmentRefresh::PROCESSING])
                        ->where('lease_expires_at', '<=', now());
                });
            })
            ->orderBy('resource_id')
            ->limit(100)
            ->pluck('resource_id')
            ->each(fn (int $resourceId) => $this->dispatch($resourceId));
    }

    public function dispatch(int $resourceId): void
    {
        DB::transaction(function () use ($resourceId): void {
            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($resourceId);
            if ($refresh === null || ! (
                ($refresh->status === ResourceAssessmentRefresh::PENDING && $refresh->available_at?->isPast())
                || (in_array($refresh->status, [ResourceAssessmentRefresh::QUEUED, ResourceAssessmentRefresh::PROCESSING], true)
                    && $refresh->lease_expires_at?->isPast())
            )) {
                return;
            }

            $refresh->forceFill([
                'status' => ResourceAssessmentRefresh::QUEUED,
                // A queued job can wait behind a full run on the shared worker.
                // Keep its claim long enough to avoid minute-by-minute duplicates.
                'lease_expires_at' => now()->addDay(),
            ])->save();

            RefreshPublishedResourceAssessmentJob::dispatch($resourceId)
                ->onConnection($this->queue->connection())
                ->onQueue($this->queue->queue())
                ->afterCommit();
        }, 3);
    }
}
