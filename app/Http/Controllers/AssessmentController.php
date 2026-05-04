<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\RunResourceAssessmentsJob;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\ResourceCacheService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly FujiAssessmentService $fujiService,
        private readonly ResourceCacheService $resourceCache,
    ) {}

    public function index(): Response
    {
        $physicalObjectTypeId = $this->resourceCache->getPhysicalObjectTypeId();

        return Inertia::render('assessment', [
            'fujiConfigured' => $this->fujiService->isConfigured(),
            'resourcesNeedingAttention' => $this->buildAttentionList(RunResourceAssessmentsJob::RESOURCE_SCOPE, $physicalObjectTypeId),
            'igsnsNeedingAttention' => $this->buildAttentionList(RunResourceAssessmentsJob::IGSN_SCOPE, $physicalObjectTypeId),
            'resourceAssessmentSummary' => $this->buildSummary(RunResourceAssessmentsJob::RESOURCE_SCOPE, $physicalObjectTypeId),
            'igsnAssessmentSummary' => $this->buildSummary(RunResourceAssessmentsJob::IGSN_SCOPE, $physicalObjectTypeId),
        ]);
    }

    public function checkResources(): JsonResponse
    {
        return $this->startScopeJob(RunResourceAssessmentsJob::RESOURCE_SCOPE);
    }

    public function checkIgsns(): JsonResponse
    {
        return $this->startScopeJob(RunResourceAssessmentsJob::IGSN_SCOPE);
    }

    public function checkAll(): JsonResponse
    {
        if (! $this->fujiService->isConfigured()) {
            return response()->json([
                'error' => 'F-UJI is not configured.',
            ], 503);
        }

        $result = [];

        foreach (RunResourceAssessmentsJob::SCOPES as $scope) {
            $started = $this->attemptScopeDispatch($scope);

            if (isset($started['jobId'])) {
                $result["{$scope}JobId"] = $started['jobId'];

                continue;
            }

            if (isset($started['error'])) {
                $result["{$scope}Error"] = $started['error'];
            }
        }

        $hasJobIds = collect($result)->keys()->contains(fn (string $key): bool => str_ends_with($key, 'JobId'));

        if (! $hasJobIds) {
            return response()->json([
                ...$result,
                'error' => 'All assessment jobs are already running. Please wait for them to finish.',
            ], 409);
        }

        return response()->json($result);
    }

    public function status(string $scope, string $jobId): JsonResponse
    {
        if (! in_array($scope, RunResourceAssessmentsJob::SCOPES, true)) {
            return response()->json(['error' => 'Unknown assessment scope.'], 404);
        }

        $cacheKey = RunResourceAssessmentsJob::getCacheKey($scope, $jobId);
        $status = Cache::get($cacheKey);

        if (! is_array($status)) {
            return response()->json([
                'status' => 'unknown',
                'progress' => 'Job not found.',
            ], 404);
        }

        unset($status['lockOwner']);

        return response()->json($status);
    }

    /**
     * @return array{jobId?: string, error?: string}
     */
    private function attemptScopeDispatch(string $scope): array
    {
        $lockKey = $this->lockKey($scope);
        $lock = Cache::lock($lockKey, RunResourceAssessmentsJob::LOCK_TTL_SECONDS);

        if (! $lock->get()) {
            return [
                'error' => sprintf('%s assessment is already running.', $this->scopeLabel($scope)),
            ];
        }

        $jobId = Str::uuid()->toString();

        try {
            Cache::put(RunResourceAssessmentsJob::getCacheKey($scope, $jobId), [
                'status' => 'queued',
                'progress' => sprintf('%s assessment is waiting to start.', $this->scopeLabel($scope)),
                'startedAt' => now()->toIso8601String(),
                'lockOwner' => $lock->owner(),
            ], now()->addSeconds(RunResourceAssessmentsJob::STATUS_TTL_SECONDS));

            RunResourceAssessmentsJob::dispatch(
                scope: $scope,
                jobId: $jobId,
                lockOwner: $lock->owner(),
                lockKey: $lockKey,
            );
        } catch (\Throwable $exception) {
            $lock->release();
            Cache::forget(RunResourceAssessmentsJob::getCacheKey($scope, $jobId));

            throw $exception;
        }

        return ['jobId' => $jobId];
    }

    /**
     * @return array{total: int, assessed: int, failed: int, skipped: int, unassessed: int}
     */
    private function buildSummary(string $scope, ?int $physicalObjectTypeId): array
    {
        $total = $scope === RunResourceAssessmentsJob::IGSN_SCOPE
            ? $this->resourceCache->getIgsnCount($physicalObjectTypeId)
            : $this->resourceCache->getDataResourceCount($physicalObjectTypeId);

        $statusCounts = $this->buildScopeQuery($scope, $physicalObjectTypeId)
            ->join('resource_assessments', 'resource_assessments.resource_id', '=', 'resources.id')
            ->selectRaw('resource_assessments.status as status, COUNT(*) as aggregate')
            ->groupBy('resource_assessments.status')
            ->pluck('aggregate', 'status');

        $assessed = (int) ($statusCounts[ResourceAssessment::STATUS_COMPLETED] ?? 0);
        $failed = (int) ($statusCounts[ResourceAssessment::STATUS_FAILED] ?? 0);
        $skipped = (int) ($statusCounts[ResourceAssessment::STATUS_SKIPPED] ?? 0);

        return [
            'total' => $total,
            'assessed' => $assessed,
            'failed' => $failed,
            'skipped' => $skipped,
            'unassessed' => max($total - $assessed - $failed - $skipped, 0),
        ];
    }

    /**
     * @return list<array{id: int, doi: string|null, mainTitle: string, score: float, assessedAt: string|null}>
     */
    private function buildAttentionList(string $scope, ?int $physicalObjectTypeId): array
    {
        $items = $this->buildScopeQuery($scope, $physicalObjectTypeId)
            ->join('resource_assessments', 'resource_assessments.resource_id', '=', 'resources.id')
            ->where('resource_assessments.status', ResourceAssessment::STATUS_COMPLETED)
            ->whereNotNull('resource_assessments.total_score')
            ->with(['titles.titleType', 'resourceAssessment'])
            ->orderBy('resource_assessments.total_score')
            ->select('resources.*')
            ->limit(10)
            ->get()
            ->map(fn (Resource $resource): array => [
                'id' => $resource->id,
                'doi' => $resource->doi,
                'mainTitle' => $resource->main_title ?? 'Untitled',
                'score' => round((float) ($resource->resourceAssessment->total_score ?? 0), 2),
                'assessedAt' => $resource->resourceAssessment?->assessed_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        /** @var list<array{id: int, doi: string|null, mainTitle: string, score: float, assessedAt: string|null}> $items */
        return $items;
    }

    /**
     * @return Builder<Resource>
     */
    private function buildScopeQuery(string $scope, ?int $physicalObjectTypeId): Builder
    {
        $query = Resource::query();

        if ($scope === RunResourceAssessmentsJob::IGSN_SCOPE) {
            if ($physicalObjectTypeId === null) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where('resource_type_id', $physicalObjectTypeId);
        }

        if ($physicalObjectTypeId === null) {
            return $query;
        }

        return $query->where(function (Builder $builder) use ($physicalObjectTypeId): void {
            $builder->whereNull('resource_type_id')
                ->orWhere('resource_type_id', '!=', $physicalObjectTypeId);
        });
    }

    private function lockKey(string $scope): string
    {
        return "resource_assessment:{$scope}:running";
    }

    private function scopeLabel(string $scope): string
    {
        return $scope === RunResourceAssessmentsJob::IGSN_SCOPE ? 'IGSN' : 'Resource';
    }

    private function startScopeJob(string $scope): JsonResponse
    {
        if (! $this->fujiService->isConfigured()) {
            return response()->json([
                'error' => 'F-UJI is not configured.',
            ], 503);
        }

        $started = $this->attemptScopeDispatch($scope);

        if (! isset($started['jobId'])) {
            return response()->json([
                'error' => $started['error'] ?? 'Assessment could not be started.',
            ], 409);
        }

        return response()->json([
            'jobId' => $started['jobId'],
        ]);
    }
}