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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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
                    'claim_token' => null,
                    'queue_job_id' => null,
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

            $leaseSeconds = max(
                (int) config('fuji.assessment.lease_seconds', 390),
                (int) config('fuji.assessment.item_timeout_seconds', 330) + 30,
            );
            if ($refresh->status === ResourceAssessmentRefresh::QUEUED
                && $refresh->queue_job_id !== null && $this->queuedJobExists($refresh->queue_job_id)) {
                // A queued job can wait behind a full run; renew its lease without duplicating it.
                $refresh->forceFill(['lease_expires_at' => now()->addSeconds($leaseSeconds)])->save();

                return;
            }

            $dispatchToken = Str::uuid()->toString();
            $refresh->forceFill([
                'status' => ResourceAssessmentRefresh::QUEUED,
                // A new dispatch token revokes both old queued jobs and expired workers.
                'claim_token' => $dispatchToken,
                'queue_job_id' => null,
                'lease_expires_at' => now()->addSeconds($leaseSeconds),
            ])->save();

            $queueDatabase = config('queue.connections.'.$this->queue->connection().'.connection');
            if ($this->queue->driver() === 'database'
                && (! is_string($queueDatabase) || $queueDatabase === '' || $queueDatabase === DB::getDefaultConnection())) {
                // Store the job and refresh in one transaction. The durable job ID
                // lets recovery distinguish a backlog from a missing queue row.
                $job = (new RefreshPublishedResourceAssessmentJob($resourceId, $refresh->generation, $dispatchToken))
                    ->onConnection($this->queue->connection())
                    ->onQueue($this->queue->queue());
                $queueJobId = Queue::connection($this->queue->connection())->push($job, '', $this->queue->queue());
                if (is_numeric($queueJobId)) {
                    $refresh->forceFill(['queue_job_id' => (int) $queueJobId])->save();
                }
            } else {
                // Other queue backends cannot join the refresh transaction.
                // Preserve after-commit dispatch for these configurations.
                RefreshPublishedResourceAssessmentJob::dispatch($resourceId, $refresh->generation, $dispatchToken)
                    ->onConnection($this->queue->connection())
                    ->onQueue($this->queue->queue())
                    ->afterCommit();
            }
        }, 3);
    }

    private function queuedJobExists(int $queueJobId): bool
    {
        if ($this->queue->driver() !== 'database') {
            return false;
        }

        $connection = $this->queue->connection();
        $database = config("queue.connections.{$connection}.connection");
        $table = (string) config("queue.connections.{$connection}.table", 'jobs');

        return DB::connection(is_string($database) ? $database : null)
            ->table($table)->where('id', $queueJobId)->exists();
    }
}
