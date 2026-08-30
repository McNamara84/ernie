<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\IgsnRegistrationItemStatus;
use App\Enums\IgsnRegistrationRunStatus;
use App\Jobs\ProcessIgsnRegistrationRunJob;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use App\Models\User;
use App\Support\IgsnBulkOperation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class IgsnRegistrationRunService
{
    private const START_LOCK_KEY = 'igsn:registration:start';

    public function __construct(
        private readonly DataCiteModeResolverService $modeResolver,
        private readonly DataCiteQueueService $queueConnection,
    ) {}

    /** @param list<int> $ids */
    public function start(array $ids, User $user): IgsnRegistrationRun
    {
        $this->ensurePersistentQueue();

        $lock = Cache::lock(self::START_LOCK_KEY, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'run' => ['Another IGSN registration run is being prepared.'],
            ]);
        }

        try {
            $resources = $this->loadResources($ids);
            $this->validateResources($ids, $resources);
            $this->ensureNoActiveOverlap($ids);

            $testMode = $this->modeResolver->shouldUseTestMode($user);
            $configuration = $this->modeResolver->dataCiteConfig($user);
            $endpoint = rtrim(trim((string) ($configuration['endpoint'] ?? '')), '/');
            if ($endpoint === '') {
                throw ValidationException::withMessages([
                    'datacite' => ['The DataCite endpoint is not configured.'],
                ]);
            }

            $run = DB::transaction(function () use ($ids, $resources, $user, $testMode, $endpoint): IgsnRegistrationRun {
                $run = IgsnRegistrationRun::query()->create([
                    'initiated_by_user_id' => $user->id,
                    'status' => IgsnRegistrationRunStatus::PREPARING,
                    'test_mode' => $testMode,
                    'datacite_endpoint' => $endpoint,
                    'total' => count($ids),
                ]);

                $resourcesById = $resources->keyBy('id');
                $rows = [];
                $now = now();

                foreach ($ids as $id) {
                    /** @var Resource $resource */
                    $resource = $resourcesById->get($id);
                    $metadata = $resource->igsnMetadata;
                    assert($metadata !== null);

                    $rows[] = [
                        'run_id' => $run->id,
                        'resource_id' => $resource->id,
                        'identifier' => trim((string) $resource->doi),
                        'status' => IgsnRegistrationItemStatus::PENDING->value,
                        'operation' => $metadata->isRegistered() ? 'update' : 'register',
                        'attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($rows) >= IgsnBulkOperation::DATABASE_CHUNK_SIZE) {
                        IgsnRegistrationItem::query()->insert($rows);
                        $rows = [];
                    }
                }

                if ($rows !== []) {
                    IgsnRegistrationItem::query()->insert($rows);
                }

                $run->status = IgsnRegistrationRunStatus::QUEUED;
                $run->save();

                ProcessIgsnRegistrationRunJob::dispatch($run->id)
                    ->onQueue($this->queueConnection->queue())
                    ->afterCommit();

                return $run;
            });

            return $run->refresh();
        } finally {
            $lock->release();
        }
    }

    public function cancel(IgsnRegistrationRun $run, User $user): IgsnRegistrationRun
    {
        return DB::transaction(function () use ($run, $user): IgsnRegistrationRun {
            $run = IgsnRegistrationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, [
                IgsnRegistrationRunStatus::PREPARING,
                IgsnRegistrationRunStatus::QUEUED,
                IgsnRegistrationRunStatus::RUNNING,
                IgsnRegistrationRunStatus::PAUSED,
            ], true)) {
                throw ValidationException::withMessages([
                    'run' => ['This IGSN registration run cannot be cancelled in its current state.'],
                ]);
            }

            $run->update([
                'status' => IgsnRegistrationRunStatus::CANCEL_REQUESTED,
                'last_controlled_by_user_id' => $user->id,
            ]);

            $this->dispatch($run);

            return $run;
        });
    }

    public function resume(IgsnRegistrationRun $run, User $user): IgsnRegistrationRun
    {
        $this->ensurePersistentQueue();

        return DB::transaction(function () use ($run, $user): IgsnRegistrationRun {
            $run = IgsnRegistrationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== IgsnRegistrationRunStatus::PAUSED) {
                throw ValidationException::withMessages([
                    'run' => ['Only a paused IGSN registration run can be resumed.'],
                ]);
            }

            IgsnRegistrationItem::query()
                ->where('run_id', $run->id)
                ->where('status', IgsnRegistrationItemStatus::PROCESSING)
                ->update(['status' => IgsnRegistrationItemStatus::PENDING]);

            $run->update([
                'status' => IgsnRegistrationRunStatus::QUEUED,
                'last_controlled_by_user_id' => $user->id,
                'pause_reason' => null,
                'last_error' => null,
                'paused_at' => null,
            ]);

            $this->dispatch($run);

            return $run;
        });
    }

    public function retryFailed(IgsnRegistrationRun $run, User $user): IgsnRegistrationRun
    {
        $this->ensurePersistentQueue();

        return DB::transaction(function () use ($run, $user): IgsnRegistrationRun {
            $run = IgsnRegistrationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, [
                IgsnRegistrationRunStatus::COMPLETED,
                IgsnRegistrationRunStatus::FAILED,
                IgsnRegistrationRunStatus::CANCELLED,
            ], true)) {
                throw ValidationException::withMessages([
                    'run' => ['Failed items cannot be retried while this run is active.'],
                ]);
            }

            $updated = IgsnRegistrationItem::query()
                ->where('run_id', $run->id)
                ->where('status', IgsnRegistrationItemStatus::FAILED)
                ->update([
                    'status' => IgsnRegistrationItemStatus::PENDING,
                    'last_http_status' => null,
                    'error_message' => null,
                    'processed_at' => null,
                ]);

            if ($updated === 0) {
                throw ValidationException::withMessages([
                    'run' => ['This IGSN registration run has no failed items to retry.'],
                ]);
            }

            $this->recalculate($run);
            $run->update([
                'status' => IgsnRegistrationRunStatus::QUEUED,
                'last_controlled_by_user_id' => $user->id,
                'pause_reason' => null,
                'last_error' => null,
                'paused_at' => null,
                'cancelled_at' => null,
                'completed_at' => null,
            ]);

            $this->dispatch($run);

            return $run;
        });
    }

    public function recalculate(IgsnRegistrationRun $run): void
    {
        $items = IgsnRegistrationItem::query()->where('run_id', $run->id)->get(['status']);
        $run->forceFill([
            'total' => $items->count(),
            'processed' => $items->filter(fn (IgsnRegistrationItem $item): bool => $item->status->isProcessed())->count(),
            'registered' => $items->where('status', IgsnRegistrationItemStatus::REGISTERED)->count(),
            'updated' => $items->where('status', IgsnRegistrationItemStatus::UPDATED)->count(),
            'failed' => $items->where('status', IgsnRegistrationItemStatus::FAILED)->count(),
            'cancelled' => $items->where('status', IgsnRegistrationItemStatus::CANCELLED)->count(),
        ])->save();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Resource>
     */
    private function loadResources(array $ids): Collection
    {
        /** @var Collection<int, Resource> $resources */
        $resources = new Collection;

        foreach (array_chunk($ids, IgsnBulkOperation::DATABASE_CHUNK_SIZE) as $chunk) {
            $resources->push(...Resource::query()
                ->with(['igsnMetadata', 'landingPage'])
                ->whereIn('id', $chunk)
                ->get());
        }

        return $resources;
    }

    /**
     * @param  list<int>  $ids
     * @param  Collection<int, Resource>  $resources
     */
    private function validateResources(array $ids, Collection $resources): void
    {
        $resourcesById = $resources->keyBy('id');

        foreach ($ids as $index => $id) {
            $resource = $resourcesById->get($id);
            if (! $resource instanceof Resource || $resource->igsnMetadata === null) {
                throw ValidationException::withMessages([
                    "ids.{$index}" => ['The selected resource does not exist or is not an IGSN.'],
                ]);
            }

            if ($resource->landingPage === null) {
                throw ValidationException::withMessages([
                    "ids.{$index}" => ['The selected IGSN must have a landing page before registration.'],
                ]);
            }
        }
    }

    /** @param list<int> $ids */
    private function ensureNoActiveOverlap(array $ids): void
    {
        foreach (array_chunk($ids, IgsnBulkOperation::DATABASE_CHUNK_SIZE) as $chunk) {
            $overlap = IgsnRegistrationItem::query()
                ->whereIn('resource_id', $chunk)
                ->whereHas('run', fn ($query) => $query->whereIn('status', IgsnRegistrationRunStatus::activeValues()))
                ->pluck('identifier')
                ->all();

            if ($overlap !== []) {
                throw ValidationException::withMessages([
                    'ids' => ['Some selected IGSNs are already part of an active registration run: '.implode(', ', array_slice($overlap, 0, 5))],
                ]);
            }
        }
    }

    private function dispatch(IgsnRegistrationRun $run): void
    {
        ProcessIgsnRegistrationRunJob::dispatch($run->id)
            ->onQueue($this->queueConnection->queue())
            ->afterCommit();
    }

    private function ensurePersistentQueue(): void
    {
        if (! $this->queueConnection->isPersistent()) {
            throw ValidationException::withMessages([
                'queue' => ['A persistent queue connection is required for IGSN batch registration.'],
            ]);
        }
    }
}
