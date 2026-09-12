<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\AssessmentFailureType;
use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Enums\CacheKey;
use App\Jobs\DispatchAssessmentRunItemsJob;
use App\Jobs\PrepareAssessmentRunSnapshotJob;
use App\Models\AssessmentRun;
use App\Models\AssessmentRunItem;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\User;
use App\Services\ResourceCacheService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final class AssessmentRunService
{
    public function __construct(
        private readonly ResourceCacheService $resourceCache,
        private readonly AssessmentQueueService $queue,
    ) {}

    public function startOrResume(AssessmentScope $scope, User $user): AssessmentRun
    {
        $this->ensurePersistentQueue();
        $lock = Cache::lock(CacheKey::ASSESSMENT_RUN_START_LOCK->key($scope->value), 120);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            $active = $this->activeForScope($scope);
            if ($active !== null) {
                return $active;
            }

            throw ValidationException::withMessages([
                'run' => ["Another {$scope->singularLabel()} assessment is being prepared."],
            ]);
        }

        $shouldDispatch = false;

        try {
            $run = DB::transaction(function () use ($scope, $user, &$shouldDispatch): AssessmentRun {
                $active = AssessmentRun::query()
                    ->where('active_scope', $scope->value)
                    ->lockForUpdate()
                    ->first();

                if ($active !== null) {
                    if ($active->status === AssessmentRunStatus::PAUSED) {
                        $this->resumeLocked($active, $user);
                        $shouldDispatch = true;
                    } elseif ($active->status !== AssessmentRunStatus::PREPARING) {
                        $shouldDispatch = true;
                    }

                    return $active;
                }

                $shouldDispatch = true;

                return $this->createRun($scope, $user);
            });
        } catch (QueryException $exception) {
            if (! $this->isActiveScopeConflict($exception)) {
                throw $exception;
            }

            $run = AssessmentRun::query()
                ->where('active_scope', $scope->value)
                ->firstOrFail();
        } finally {
            $lock->release();
        }

        if ($shouldDispatch && $run->status->isActive() && $run->status !== AssessmentRunStatus::PAUSED) {
            $this->dispatch($run);
        }

        return $run->refresh();
    }

    public function activeForScope(AssessmentScope $scope): ?AssessmentRun
    {
        return AssessmentRun::query()
            ->where('active_scope', $scope->value)
            ->latest('created_at')
            ->first();
    }

    public function latestForScope(AssessmentScope $scope): ?AssessmentRun
    {
        return $this->activeForScope($scope) ?? AssessmentRun::query()
            ->where('scope', $scope->value)
            ->latest('created_at')
            ->first();
    }

    public function configurationMatches(AssessmentRun $run): bool
    {
        $configuration = $this->configurationSnapshot();

        return rtrim($run->fuji_base_url, '/') === rtrim($configuration['fuji_base_url'], '/')
            && $run->metric_version === $configuration['metric_version']
            && $run->use_datacite === $configuration['use_datacite']
            && $run->use_github === $configuration['use_github']
            && $run->concurrency === $configuration['concurrency']
            && $run->requests_per_minute === $configuration['requests_per_minute'];
    }

    public function resume(AssessmentRun $run, User $user): AssessmentRun
    {
        $this->ensurePersistentQueue();
        $lock = Cache::lock(CacheKey::ASSESSMENT_RUN_START_LOCK->key($run->scope->value), 120);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'run' => ["The {$run->scope->singularLabel()} assessment is currently being controlled by another request."],
            ]);
        }

        try {
            $resumed = DB::transaction(function () use ($run, $user): AssessmentRun {
                $locked = AssessmentRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($locked->status->isTerminal()) {
                    throw ValidationException::withMessages(['run' => ['A completed or cancelled assessment run cannot be resumed.']]);
                }

                if ($locked->status === AssessmentRunStatus::PAUSED) {
                    $this->resumeLocked($locked, $user);
                }

                return $locked;
            });
        } finally {
            $lock->release();
        }

        if ($resumed->status->isActive() && $resumed->status !== AssessmentRunStatus::PAUSED) {
            $this->dispatch($resumed);
        }

        return $resumed->refresh();
    }

    public function cancel(AssessmentRun $run, User $user): AssessmentRun
    {
        return DB::transaction(function () use ($run, $user): AssessmentRun {
            $locked = AssessmentRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            AssessmentRunItem::query()
                ->where('run_id', $locked->id)
                ->whereIn('status', AssessmentRunItemStatus::openValues())
                ->update([
                    'status' => AssessmentRunItemStatus::CANCELLED,
                    'available_at' => null,
                    'processing_started_at' => null,
                    'lease_expires_at' => null,
                    'processed_at' => now(),
                ]);

            $this->recalculate($locked);
            $locked->refresh();
            $locked->forceFill([
                'status' => AssessmentRunStatus::CANCELLED,
                'active_scope' => null,
                'last_controlled_by_user_id' => $user->id,
                'cancelled_at' => now(),
                'completed_at' => now(),
            ])->save();

            Log::info(sprintf('%s assessment run cancelled', $locked->scope->singularLabel()), [
                'run_id' => $locked->id,
                'scope' => $locked->scope->value,
                'processed' => $locked->processed,
                'pending' => $locked->pending,
            ]);

            return $locked;
        }, 3);
    }

    public function retryServiceFailures(AssessmentRun $run, User $user): AssessmentRun
    {
        $this->ensurePersistentQueue();
        $lock = Cache::lock(CacheKey::ASSESSMENT_RUN_START_LOCK->key($run->scope->value), 120);

        try {
            $lock->block(15);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'run' => ["The {$run->scope->singularLabel()} assessment is currently being controlled by another request."],
            ]);
        }

        try {
            $retried = DB::transaction(function () use ($run, $user): AssessmentRun {
                $locked = AssessmentRun::query()->lockForUpdate()->findOrFail($run->id);

                if ($locked->status !== AssessmentRunStatus::COMPLETED) {
                    throw ValidationException::withMessages([
                        'run' => ['Only a completed assessment run can retry service errors.'],
                    ]);
                }

                $otherActiveRunExists = AssessmentRun::query()
                    ->where('active_scope', $locked->scope->value)
                    ->where('id', '!=', $locked->id)
                    ->exists();

                if ($otherActiveRunExists) {
                    throw ValidationException::withMessages([
                        'run' => ["Another {$locked->scope->singularLabel()} assessment is already active."],
                    ]);
                }

                $serviceItems = AssessmentRunItem::query()
                    ->where('run_id', $locked->id)
                    ->where('status', AssessmentRunItemStatus::FAILED)
                    ->where('failure_type', AssessmentFailureType::SERVICE)
                    ->lockForUpdate()
                    ->get();

                if ($serviceItems->isEmpty()) {
                    throw ValidationException::withMessages([
                        'run' => ['This assessment run has no service errors to retry.'],
                    ]);
                }

                $resourceIds = $serviceItems->pluck('resource_id')->filter()->values();
                if ($resourceIds->isNotEmpty()) {
                    ResourceAssessment::query()
                        ->whereIn('resource_id', $resourceIds)
                        ->where('failure_type', AssessmentFailureType::SERVICE)
                        ->delete();
                }

                AssessmentRunItem::query()
                    ->whereKey($serviceItems->modelKeys())
                    ->update([
                        'status' => AssessmentRunItemStatus::PENDING,
                        'attempts' => 0,
                        'last_http_status' => null,
                        'failure_type' => null,
                        'error_code' => null,
                        'error_message' => null,
                        'error_detail' => null,
                        'last_attempt_duration_ms' => null,
                        'available_at' => null,
                        'processing_started_at' => null,
                        'lease_expires_at' => null,
                        'processed_at' => null,
                    ]);

                $this->recalculate($locked);
                $locked->refresh();
                $locked->forceFill([
                    ...$this->configurationSnapshot(requireConfigured: true),
                    'status' => AssessmentRunStatus::QUEUED,
                    'active_scope' => $locked->scope,
                    'last_controlled_by_user_id' => $user->id,
                    'pause_reason' => null,
                    'last_error' => null,
                    'started_at' => now(),
                    'paused_at' => null,
                    'cancelled_at' => null,
                    'completed_at' => null,
                ])->save();

                Log::info(sprintf('%s assessment service errors queued for retry', $locked->scope->singularLabel()), [
                    'run_id' => $locked->id,
                    'scope' => $locked->scope->value,
                    'retry_count' => $serviceItems->count(),
                ]);

                return $locked;
            }, 3);
        } finally {
            $lock->release();
        }

        $this->dispatch($retried);

        return $retried->refresh();
    }

    public function pause(AssessmentRun $run, string $reason, ?string $error = null): void
    {
        if ($run->status->isTerminal()) {
            return;
        }

        $run->forceFill([
            'status' => AssessmentRunStatus::PAUSED,
            'pause_reason' => $this->sanitize($reason),
            'last_error' => $error === null ? null : $this->sanitize($error),
            'paused_at' => now(),
        ])->save();

        Log::warning(sprintf('%s assessment run paused', $run->scope->singularLabel()), [
            'run_id' => $run->id,
            'scope' => $run->scope->value,
            'processed' => $run->processed,
            'pending' => $run->pending,
            'reason' => $run->pause_reason,
            'error' => $run->last_error,
        ]);
    }

    public function recalculate(AssessmentRun $run): void
    {
        $counts = AssessmentRunItem::query()
            ->where('run_id', $run->id)
            ->selectRaw('status, failure_type, COUNT(*) as aggregate')
            ->groupBy('status', 'failure_type')
            ->get();

        $countStatus = static fn (AssessmentRunItemStatus $status): int => (int) $counts
            ->where('status', $status->value)
            ->sum('aggregate');
        $assessed = $countStatus(AssessmentRunItemStatus::ASSESSED);
        $allFailures = $countStatus(AssessmentRunItemStatus::FAILED);
        $serviceErrors = (int) $counts
            ->where('status', AssessmentRunItemStatus::FAILED->value)
            ->where('failure_type', AssessmentFailureType::SERVICE->value)
            ->sum('aggregate');
        $failed = max(0, $allFailures - $serviceErrors);
        $skipped = $countStatus(AssessmentRunItemStatus::SKIPPED)
            + $countStatus(AssessmentRunItemStatus::CANCELLED);
        $processed = $assessed + $failed + $serviceErrors + $skipped;

        $run->forceFill([
            'total' => (int) $counts->sum('aggregate'),
            'processed' => $processed,
            'assessed' => $assessed,
            'failed' => $failed,
            'service_errors' => $serviceErrors,
            'skipped' => $skipped,
            'pending' => max((int) $counts->sum('aggregate') - $processed, 0),
        ])->save();
    }

    public function dispatch(AssessmentRun $run, int $delaySeconds = 0): void
    {
        $run->refresh();
        if ($run->status->isTerminal() || $run->status === AssessmentRunStatus::PAUSED) {
            return;
        }

        $job = $run->status === AssessmentRunStatus::PREPARING
            ? new PrepareAssessmentRunSnapshotJob($run->id)
            : new DispatchAssessmentRunItemsJob($run->id);

        dispatch($job)
            ->onConnection($this->queue->connection())
            ->onQueue($this->queue->queue())
            ->delay(now()->addSeconds(max(0, $delaySeconds)))
            ->afterCommit();
    }

    public function prepareNextSnapshotChunk(string $runId): void
    {
        $nextAction = DB::transaction(function () use ($runId): ?string {
            $run = AssessmentRun::query()->lockForUpdate()->find($runId);

            if ($run === null || $run->status !== AssessmentRunStatus::PREPARING) {
                return null;
            }

            if (! $this->configurationMatches($run)) {
                $this->pause($run, 'F-UJI configuration changed while the assessment run was being prepared.');

                return null;
            }

            $chunkSize = max(1, min(1000, (int) config('fuji.assessment.snapshot_chunk_size', 250)));
            $resources = $this->scopeQuery($run->scope)
                ->where('resources.id', '>', $run->preparation_cursor)
                ->where('resources.id', '<=', $run->snapshot_max_resource_id)
                ->orderBy('resources.id')
                ->limit($chunkSize)
                ->get(['resources.id', 'resources.doi']);
            $now = now();
            $itemRows = [];
            $assessmentRows = [];

            foreach ($resources as $resource) {
                $identifier = is_string($resource->doi) && trim($resource->doi) !== '' ? trim($resource->doi) : null;
                $status = $identifier === null ? AssessmentRunItemStatus::SKIPPED : AssessmentRunItemStatus::PENDING;
                $itemRows[] = [
                    'run_id' => $run->id,
                    'resource_id' => $resource->id,
                    'identifier' => $identifier,
                    'status' => $status->value,
                    'attempts' => 0,
                    'error_message' => $identifier === null ? 'Resource has no DOI.' : null,
                    'processed_at' => $identifier === null ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ($identifier === null) {
                    $assessmentRows[] = [
                        'resource_id' => $resource->id,
                        'status' => ResourceAssessment::STATUS_SKIPPED,
                        'total_score' => null,
                        'assessed_identifier' => null,
                        'error_message' => 'Resource has no DOI.',
                        'payload' => null,
                        'assessed_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            if ($itemRows !== []) {
                AssessmentRunItem::query()->insertOrIgnore($itemRows);
            }

            if ($assessmentRows !== []) {
                ResourceAssessment::query()->upsert(
                    $assessmentRows,
                    ['resource_id'],
                    ['status', 'total_score', 'assessed_identifier', 'error_message', 'payload', 'assessed_at', 'updated_at'],
                );
            }

            if ($resources->isNotEmpty()) {
                $run->preparation_cursor = (int) $resources->last()->id;
                $run->save();
            }

            $this->recalculate($run);
            $run->refresh();

            if ($resources->count() === $chunkSize) {
                return 'prepare';
            }

            $completedAt = $run->pending === 0 ? now() : null;
            $run->forceFill([
                'status' => $run->pending === 0 ? AssessmentRunStatus::COMPLETED : AssessmentRunStatus::QUEUED,
                'active_scope' => $run->pending === 0 ? null : $run->scope,
                'prepared_at' => now(),
                'completed_at' => $completedAt,
            ])->save();

            return $run->pending === 0 ? null : 'dispatch';
        }, 3);

        if ($nextAction === null) {
            return;
        }

        $run = AssessmentRun::query()->find($runId);
        if ($run !== null) {
            $this->dispatch($run);
        }
    }

    private function createRun(AssessmentScope $scope, User $user): AssessmentRun
    {
        $configuration = $this->configurationSnapshot(requireConfigured: true);
        $snapshotMaxResourceId = Resource::query()->max('id');

        return AssessmentRun::query()->create([
            'scope' => $scope,
            'status' => AssessmentRunStatus::PREPARING,
            'active_scope' => $scope,
            'initiated_by_user_id' => $user->id,
            ...$configuration,
            'snapshot_max_resource_id' => is_numeric($snapshotMaxResourceId) ? (int) $snapshotMaxResourceId : 0,
            'preparation_cursor' => 0,
            'prepared_at' => null,
        ]);
    }

    /** @return Builder<Resource> */
    private function scopeQuery(AssessmentScope $scope): Builder
    {
        $query = Resource::query();
        $physicalObjectTypeId = $this->resourceCache->getPhysicalObjectTypeId();

        if ($scope === AssessmentScope::IGSN) {
            return $physicalObjectTypeId === null
                ? $query->whereRaw('1 = 0')
                : $query->where('resource_type_id', $physicalObjectTypeId);
        }

        if ($physicalObjectTypeId === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($physicalObjectTypeId): void {
            $builder->whereNull('resource_type_id')
                ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
        });
    }

    private function resetOpenItems(AssessmentRun $run): void
    {
        AssessmentRunItem::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [AssessmentRunItemStatus::QUEUED, AssessmentRunItemStatus::PROCESSING])
            ->update([
                'status' => AssessmentRunItemStatus::PENDING,
                'available_at' => null,
                'processing_started_at' => null,
                'lease_expires_at' => null,
            ]);

        $this->recalculate($run);
    }

    private function resumeLocked(AssessmentRun $run, User $user): void
    {
        $this->resetOpenItems($run);
        $run->forceFill([
            ...$this->configurationSnapshot(requireConfigured: true),
            'status' => $run->prepared_at === null ? AssessmentRunStatus::PREPARING : AssessmentRunStatus::QUEUED,
            'last_controlled_by_user_id' => $user->id,
            'pause_reason' => null,
            'last_error' => null,
            'paused_at' => null,
        ])->save();
    }

    /**
     * @return array{
     *     fuji_base_url: string,
     *     metric_version: string|null,
     *     use_datacite: bool,
     *     use_github: bool,
     *     concurrency: int,
     *     requests_per_minute: int
     * }
     */
    private function configurationSnapshot(bool $requireConfigured = false): array
    {
        $baseUrl = trim((string) config('fuji.base_url', ''));
        if ($requireConfigured && $baseUrl === '') {
            throw ValidationException::withMessages(['fuji' => ['F-UJI is not configured.']]);
        }

        $metricVersion = config('fuji.metric_version');

        return [
            'fuji_base_url' => $baseUrl,
            'metric_version' => is_string($metricVersion) && trim($metricVersion) !== '' ? trim($metricVersion) : null,
            'use_datacite' => (bool) config('fuji.use_datacite', true),
            'use_github' => (bool) config('fuji.use_github', false),
            'concurrency' => max(1, min(8, (int) config('fuji.assessment.concurrency', 2))),
            'requests_per_minute' => max(1, (int) config('fuji.assessment.requests_per_minute', 80)),
        ];
    }

    private function ensurePersistentQueue(): void
    {
        if (! $this->queue->isPersistent()) {
            throw ValidationException::withMessages([
                'queue' => ['A persistent queue connection is required for FAIR assessments.'],
            ]);
        }
    }

    private function isActiveScopeConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'active_scope')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 1000);
    }
}
