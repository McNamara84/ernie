<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Enums\DataCiteUrlUpdateScope;
use App\Jobs\ProcessDataCiteUrlUpdateRunJob;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataCiteUrlUpdateRunService
{
    private const START_LOCK_KEY = 'datacite:url-update:start';

    public function __construct(
        private readonly DataCiteUrlUpdateCandidateService $candidates,
        private readonly DataCiteUrlUpdateTargetService $target,
        private readonly DataCiteMemberApiClient $client,
        private readonly DataCiteUrlUpdateQueueService $queueConnection,
    ) {}

    public function start(DataCiteUrlUpdateScope $scope, User $user): DataCiteUrlUpdateRun
    {
        $this->ensurePersistentQueue();

        $validation = $this->target->validateTargetBase();
        if (! $validation['valid']) {
            throw ValidationException::withMessages(['target' => [$validation['message'] ?? 'The target URL is invalid.']]);
        }

        $lock = Cache::lock(self::START_LOCK_KEY, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['run' => ['Another DataCite URL update is being prepared.']]);
        }

        try {
            if (DataCiteUrlUpdateRun::query()->active()->exists()) {
                throw ValidationException::withMessages(['run' => ['Another DataCite URL update is already active.']]);
            }

            $run = DB::transaction(function () use ($scope, $user): DataCiteUrlUpdateRun {
                $run = DataCiteUrlUpdateRun::query()->create([
                    'scope' => $scope,
                    'status' => DataCiteUrlUpdateRunStatus::PREPARING,
                    'active_marker' => DataCiteUrlUpdateRun::ACTIVE_MARKER,
                    'initiated_by_user_id' => $user->id,
                    'test_mode' => $this->client->isTestMode(),
                    'datacite_endpoint' => $this->client->endpoint(),
                    'target_base_url' => $this->target->targetBaseUrl(),
                ]);

                $total = 0;
                /** @var list<array<string, mixed>> $rows */
                $rows = [];
                $now = now();

                $this->candidates->each($scope, function ($resource) use ($run, &$total, &$rows, $now): void {
                    $landingPage = $resource->landingPage;
                    if ($landingPage === null) {
                        return;
                    }

                    $rows[] = [
                        'run_id' => $run->id,
                        'resource_id' => $resource->id,
                        'identifier' => trim((string) $resource->doi),
                        'status' => DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT->value,
                        'target_url' => $this->target->buildUrl($landingPage),
                        'preflight_attempts' => 0,
                        'update_attempts' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $total++;

                    if (count($rows) >= 250) {
                        $this->flushItems($rows);
                    }
                });
                $this->flushItems($rows);

                $run->total = $total;
                if ($total === 0) {
                    $run->status = DataCiteUrlUpdateRunStatus::COMPLETED;
                    $run->completed_at = now();
                    $run->releaseActiveMarker();
                } else {
                    $run->status = DataCiteUrlUpdateRunStatus::QUEUED;
                }
                $run->save();

                return $run;
            });

            if ($run->total > 0) {
                ProcessDataCiteUrlUpdateRunJob::dispatch($run->id)
                    ->onQueue((string) config('datacite.landing_page_url_update.queue', 'datacite'))
                    ->afterCommit();
            }

            return $run->refresh();
        } catch (QueryException $exception) {
            $message = strtolower($exception->getMessage());
            if (str_contains($message, 'active_marker')
                && (str_contains($message, 'unique') || str_contains($message, 'duplicate'))) {
                throw ValidationException::withMessages(['run' => ['Another DataCite URL update is already active.']]);
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function cancel(DataCiteUrlUpdateRun $run, User $user): DataCiteUrlUpdateRun
    {
        return DB::transaction(function () use ($run, $user): DataCiteUrlUpdateRun {
            $run = DataCiteUrlUpdateRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($run->status, [
                DataCiteUrlUpdateRunStatus::PREPARING,
                DataCiteUrlUpdateRunStatus::QUEUED,
                DataCiteUrlUpdateRunStatus::RUNNING,
                DataCiteUrlUpdateRunStatus::PAUSED,
            ], true)) {
                throw ValidationException::withMessages(['run' => ['This run cannot be cancelled in its current state.']]);
            }

            $run->status = DataCiteUrlUpdateRunStatus::CANCEL_REQUESTED;
            $run->last_controlled_by_user_id = $user->id;
            $run->save();
            ProcessDataCiteUrlUpdateRunJob::dispatch($run->id)
                ->onQueue($this->queue())
                ->afterCommit();

            return $run;
        });
    }

    public function resume(DataCiteUrlUpdateRun $run, User $user): DataCiteUrlUpdateRun
    {
        return $this->reactivate($run, $user, false);
    }

    public function retryFailed(DataCiteUrlUpdateRun $run, User $user): DataCiteUrlUpdateRun
    {
        return $this->reactivate($run, $user, true);
    }

    private function reactivate(DataCiteUrlUpdateRun $run, User $user, bool $retryFailed): DataCiteUrlUpdateRun
    {
        $this->ensurePersistentQueue();

        $lock = Cache::lock(self::START_LOCK_KEY, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['run' => ['Another DataCite URL update is being controlled.']]);
        }

        try {
            return DB::transaction(function () use ($run, $user, $retryFailed): DataCiteUrlUpdateRun {
                $run = DataCiteUrlUpdateRun::query()->lockForUpdate()->findOrFail($run->id);
                $allowed = $retryFailed
                    ? [DataCiteUrlUpdateRunStatus::COMPLETED, DataCiteUrlUpdateRunStatus::FAILED, DataCiteUrlUpdateRunStatus::CANCELLED]
                    : [DataCiteUrlUpdateRunStatus::PAUSED, DataCiteUrlUpdateRunStatus::CANCELLED];

                if (! in_array($run->status, $allowed, true)) {
                    throw ValidationException::withMessages(['run' => ['This run cannot be reactivated in its current state.']]);
                }

                if (DataCiteUrlUpdateRun::query()->active()->where('id', '!=', $run->id)->exists()) {
                    throw ValidationException::withMessages(['run' => ['Another DataCite URL update is already active.']]);
                }

                if ($retryFailed) {
                    $failedItems = DataCiteUrlUpdateItem::query()
                        ->where('run_id', $run->id)
                        ->where('status', DataCiteUrlUpdateItemStatus::FAILED)
                        ->get();
                    if ($failedItems->isEmpty()) {
                        throw ValidationException::withMessages(['run' => ['This run has no failed items to retry.']]);
                    }

                    foreach ($failedItems as $item) {
                        $item->update([
                            'status' => DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT,
                            'before_url' => null,
                            'datacite_state' => null,
                            'error_message' => null,
                            'last_http_status' => null,
                            'processed_at' => null,
                            'preflight_attempts' => 0,
                            'update_attempts' => 0,
                        ]);
                    }
                } else {
                    DataCiteUrlUpdateItem::query()
                        ->where('run_id', $run->id)
                        ->where('status', DataCiteUrlUpdateItemStatus::PENDING_UPDATE)
                        ->update([
                            'status' => DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT,
                            'before_url' => null,
                            'datacite_state' => null,
                            'last_http_status' => null,
                            'error_message' => null,
                        ]);
                }

                $this->recalculate($run);
                $run->status = DataCiteUrlUpdateRunStatus::QUEUED;
                $run->active_marker = DataCiteUrlUpdateRun::ACTIVE_MARKER;
                $run->last_controlled_by_user_id = $user->id;
                $run->pause_reason = null;
                $run->last_error = null;
                $run->paused_at = null;
                $run->cancelled_at = null;
                $run->completed_at = null;
                $run->save();

                ProcessDataCiteUrlUpdateRunJob::dispatch($run->id)
                    ->onQueue($this->queue())
                    ->afterCommit();

                return $run;
            });
        } finally {
            $lock->release();
        }
    }

    public function recalculate(DataCiteUrlUpdateRun $run): void
    {
        $items = DataCiteUrlUpdateItem::query()->where('run_id', $run->id)->get(['status']);
        $run->total = $items->count();
        $run->processed = $items->filter(fn ($item): bool => $item->status->isProcessed())->count();
        $run->updated = $items->where('status', DataCiteUrlUpdateItemStatus::UPDATED)->count();
        $run->already_current = $items->where('status', DataCiteUrlUpdateItemStatus::ALREADY_CURRENT)->count();
        $run->skipped = $items->filter(fn ($item): bool => $item->status->isSkipped())->count();
        $run->failed = $items->where('status', DataCiteUrlUpdateItemStatus::FAILED)->count();
    }

    private function queue(): string
    {
        return (string) config('datacite.landing_page_url_update.queue', 'datacite');
    }

    private function ensurePersistentQueue(): void
    {
        if (! $this->queueConnection->isPersistent()) {
            throw ValidationException::withMessages([
                'queue' => ['A persistent queue connection is required for DataCite URL updates.'],
            ]);
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private function flushItems(array &$rows): void
    {
        if ($rows === []) {
            return;
        }

        DataCiteUrlUpdateItem::query()->insert($rows);
        $rows = [];
    }
}
