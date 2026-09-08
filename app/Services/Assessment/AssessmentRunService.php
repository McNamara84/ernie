<?php

declare(strict_types=1);

namespace App\Services\Assessment;

use App\Enums\AssessmentRunItemStatus;
use App\Enums\AssessmentRunStatus;
use App\Enums\AssessmentScope;
use App\Jobs\DispatchAssessmentRunItemsJob;
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
    private const SNAPSHOT_CHUNK_SIZE = 250;

    public function __construct(
        private readonly ResourceCacheService $resourceCache,
        private readonly AssessmentQueueService $queue,
    ) {}

    public function startOrResume(AssessmentScope $scope, User $user): AssessmentRun
    {
        $this->ensurePersistentQueue();
        $lock = Cache::lock("assessment:run:start:{$scope->value}", 120);

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

        try {
            $run = DB::transaction(function () use ($scope, $user): AssessmentRun {
                $active = AssessmentRun::query()
                    ->where('active_scope', $scope->value)
                    ->lockForUpdate()
                    ->first();

                if ($active !== null) {
                    if ($active->status === AssessmentRunStatus::PAUSED) {
                        $this->resetOpenItems($active);
                        $active->forceFill([
                            'status' => AssessmentRunStatus::QUEUED,
                            'last_controlled_by_user_id' => $user->id,
                            'pause_reason' => null,
                            'last_error' => null,
                            'paused_at' => null,
                        ])->save();
                    }

                    return $active;
                }

                return $this->createRunSnapshot($scope, $user);
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

        if ($run->status->isActive() && $run->status !== AssessmentRunStatus::PAUSED) {
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
        $baseUrl = trim((string) config('fuji.base_url', ''));
        $metricVersion = config('fuji.metric_version');
        $metricVersion = is_string($metricVersion) && trim($metricVersion) !== '' ? trim($metricVersion) : null;

        return rtrim($run->fuji_base_url, '/') === rtrim($baseUrl, '/')
            && $run->metric_version === $metricVersion
            && $run->use_datacite === (bool) config('fuji.use_datacite', true)
            && $run->use_github === (bool) config('fuji.use_github', false);
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

        Log::warning('Resource assessment run paused', [
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
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $assessed = (int) ($counts[AssessmentRunItemStatus::ASSESSED->value] ?? 0);
        $failed = (int) ($counts[AssessmentRunItemStatus::FAILED->value] ?? 0);
        $skipped = (int) ($counts[AssessmentRunItemStatus::SKIPPED->value] ?? 0)
            + (int) ($counts[AssessmentRunItemStatus::CANCELLED->value] ?? 0);
        $processed = $assessed + $failed + $skipped;

        $run->forceFill([
            'total' => (int) $counts->sum(),
            'processed' => $processed,
            'assessed' => $assessed,
            'failed' => $failed,
            'skipped' => $skipped,
            'pending' => max((int) $counts->sum() - $processed, 0),
        ])->save();
    }

    public function dispatch(AssessmentRun $run, int $delaySeconds = 0): void
    {
        DispatchAssessmentRunItemsJob::dispatch($run->id)
            ->onConnection($this->queue->connection())
            ->onQueue($this->queue->queue())
            ->delay(now()->addSeconds(max(0, $delaySeconds)))
            ->afterCommit();
    }

    private function createRunSnapshot(AssessmentScope $scope, User $user): AssessmentRun
    {
        $baseUrl = trim((string) config('fuji.base_url', ''));
        if ($baseUrl === '') {
            throw ValidationException::withMessages(['fuji' => ['F-UJI is not configured.']]);
        }

        $metricVersion = config('fuji.metric_version');
        $metricVersion = is_string($metricVersion) && trim($metricVersion) !== '' ? trim($metricVersion) : null;
        $run = AssessmentRun::query()->create([
            'scope' => $scope,
            'status' => AssessmentRunStatus::PREPARING,
            'active_scope' => $scope,
            'initiated_by_user_id' => $user->id,
            'fuji_base_url' => $baseUrl,
            'metric_version' => $metricVersion,
            'use_datacite' => (bool) config('fuji.use_datacite', true),
            'use_github' => (bool) config('fuji.use_github', false),
            'concurrency' => max(1, min(8, (int) config('fuji.assessment.concurrency', 2))),
            'requests_per_minute' => max(1, (int) config('fuji.assessment.requests_per_minute', 80)),
        ]);

        $total = 0;
        $skipped = 0;
        $rows = [];
        $now = now();

        foreach ($this->scopeQuery($scope)->lazyById(self::SNAPSHOT_CHUNK_SIZE) as $resource) {
            $identifier = is_string($resource->doi) && trim($resource->doi) !== '' ? trim($resource->doi) : null;
            $status = $identifier === null ? AssessmentRunItemStatus::SKIPPED : AssessmentRunItemStatus::PENDING;
            $rows[] = [
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
            $total++;

            if ($identifier === null) {
                $skipped++;
                ResourceAssessment::query()->updateOrCreate(
                    ['resource_id' => $resource->id],
                    [
                        'status' => ResourceAssessment::STATUS_SKIPPED,
                        'total_score' => null,
                        'assessed_identifier' => null,
                        'error_message' => 'Resource has no DOI.',
                        'payload' => null,
                        'assessed_at' => $now,
                    ],
                );
            }

            if (count($rows) >= self::SNAPSHOT_CHUNK_SIZE) {
                AssessmentRunItem::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            AssessmentRunItem::query()->insert($rows);
        }

        $pending = $total - $skipped;
        $run->forceFill([
            'status' => $pending === 0 ? AssessmentRunStatus::COMPLETED : AssessmentRunStatus::QUEUED,
            'active_scope' => $pending === 0 ? null : $scope,
            'total' => $total,
            'processed' => $skipped,
            'skipped' => $skipped,
            'pending' => $pending,
            'completed_at' => $pending === 0 ? now() : null,
        ])->save();

        return $run;
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
