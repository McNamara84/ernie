<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AssessmentScope;
use App\Enums\CacheKey;
use App\Enums\UserRole;
use App\Http\Requests\Assessment\IndexAssessmentRequest;
use App\Jobs\RunResourceAssessmentsJob;
use App\Models\AssessmentRun;
use App\Models\Datacenter;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\User;
use App\Services\Assessment\AssessmentRunPresenterService;
use App\Services\Assessment\AssessmentRunService;
use App\Services\Assessment\FairImprovementContextFactory;
use App\Services\Assessment\FairImprovementOpportunityResolver;
use App\Services\Assessment\FujiAssessmentService;
use App\Services\Assistance\AssistanceReviewService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceImpactFilterService;
use App\Services\Resources\ResourceQueryBuilder;
use App\Support\ResourceImpactFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class AssessmentController extends Controller
{
    public function __construct(
        private readonly FujiAssessmentService $fujiService,
        private readonly ResourceCacheService $resourceCache,
        private readonly ResourceImpactFilterService $filterService,
        private readonly ResourceQueryBuilder $resourceQueryBuilder,
        private readonly AssistanceReviewService $assistanceReviewService,
        private readonly FairImprovementContextFactory $fairImprovementContextFactory,
        private readonly FairImprovementOpportunityResolver $fairImprovementResolver,
        private readonly AssessmentRunService $assessmentRuns,
        private readonly AssessmentRunPresenterService $assessmentRunPresenter,
    ) {}

    public function index(IndexAssessmentRequest $request): Response
    {
        $physicalObjectTypeId = $this->resourceCache->getPhysicalObjectTypeId();
        $fujiHealth = $this->cachedHealthStatus();
        $includeExternalResources = $request->boolean('include_external_resources');
        $includeDraftReviewResources = $request->boolean('include_draft_review_resources');
        $filter = $request->resourceImpactFilter();
        $allowedImprovementActors = $request->user()?->role === UserRole::ADMIN
            ? ['curator', 'administrator']
            : ['curator'];

        return Inertia::render('assessment', [
            'fujiConfigured' => $this->fujiService->isConfigured(),
            'fujiHealthy' => $fujiHealth['healthy'],
            'fujiStatusMessage' => $fujiHealth['message'],
            'fujiStatusCode' => $fujiHealth['statusCode'],
            'canRunAssessments' => $request->user()?->can('run-assessment') ?? false,
            'canAccessAssistance' => $request->user()?->can('access-assistance') ?? false,
            'showImprovementActorLabels' => $request->user()?->role === UserRole::ADMIN,
            'includeExternalResources' => $includeExternalResources,
            'includeDraftReviewResources' => $includeDraftReviewResources,
            'filters' => $filter->toArray(),
            'datacenterOptions' => $this->assessmentDatacenterOptions(),
            'resourcesNeedingAttention' => $this->buildAttentionList(
                scope: RunResourceAssessmentsJob::RESOURCE_SCOPE,
                physicalObjectTypeId: $physicalObjectTypeId,
                allowedImprovementActors: $allowedImprovementActors,
                includeExternalResources: $includeExternalResources,
                includeDraftReviewResources: $includeDraftReviewResources,
                filter: $filter,
            ),
            'igsnsNeedingAttention' => $this->buildAttentionList(
                scope: RunResourceAssessmentsJob::IGSN_SCOPE,
                physicalObjectTypeId: $physicalObjectTypeId,
                allowedImprovementActors: $allowedImprovementActors,
                filter: $filter,
            ),
            'resourceAssessmentSummary' => $this->buildSummary(RunResourceAssessmentsJob::RESOURCE_SCOPE, $physicalObjectTypeId, $filter),
            'igsnAssessmentSummary' => $this->buildSummary(RunResourceAssessmentsJob::IGSN_SCOPE, $physicalObjectTypeId, $filter),
            'resourceAssessmentRun' => $this->presentLatestRun(AssessmentScope::RESOURCE),
            'igsnAssessmentRun' => $this->presentLatestRun(AssessmentScope::IGSN),
        ]);
    }

    public function checkResources(): JsonResponse
    {
        return $this->startScopeJob(AssessmentScope::RESOURCE);
    }

    public function checkIgsns(): JsonResponse
    {
        return $this->startScopeJob(AssessmentScope::IGSN);
    }

    public function checkAll(): JsonResponse
    {
        $fujiUnavailableResponse = $this->fujiUnavailableResponse();

        if ($fujiUnavailableResponse !== null) {
            return $fujiUnavailableResponse;
        }

        $result = [];

        foreach (AssessmentScope::cases() as $scope) {
            $run = $this->assessmentRuns->startOrResume($scope, $this->authenticatedUser());
            $result["{$scope->value}JobId"] = $run->id;
        }

        return response()->json($result);
    }

    public function resume(string $scope, string $jobId): JsonResponse
    {
        $fujiUnavailableResponse = $this->fujiUnavailableResponse();
        if ($fujiUnavailableResponse !== null) {
            return $fujiUnavailableResponse;
        }

        $run = $this->findRun($scope, $jobId);
        if ($run === null) {
            return response()->json(['error' => 'Assessment run not found.'], 404);
        }

        return response()->json($this->assessmentRunPresenter->present(
            $this->assessmentRuns->resume($run, $this->authenticatedUser()),
        ));
    }

    public function cancel(string $scope, string $jobId): JsonResponse
    {
        $run = $this->findRun($scope, $jobId);
        if ($run === null) {
            return response()->json(['error' => 'Assessment run not found.'], 404);
        }

        return response()->json($this->assessmentRunPresenter->present(
            $this->assessmentRuns->cancel($run, $this->authenticatedUser()),
        ));
    }

    public function status(string $scope, string $jobId): JsonResponse
    {
        $assessmentScope = AssessmentScope::tryFrom($scope);
        if ($assessmentScope === null) {
            return response()->json(['error' => 'Unknown assessment scope.'], 404);
        }
        $jobId = strtolower($jobId);

        $run = AssessmentRun::query()
            ->whereKey($jobId)
            ->where('scope', $assessmentScope->value)
            ->first();

        if ($run !== null) {
            return response()->json($this->assessmentRunPresenter->present($run));
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
     * @return array{total: int, assessed: int, failed: int, skipped: int, unassessed: int}
     */
    private function buildSummary(string $scope, ?int $physicalObjectTypeId, ResourceImpactFilter $filter): array
    {
        $total = $filter->isActive()
            ? $this->buildScopeQuery($scope, $physicalObjectTypeId, $filter)->count()
            : ($scope === RunResourceAssessmentsJob::IGSN_SCOPE
                ? $this->resourceCache->getIgsnCount($physicalObjectTypeId)
                : $this->resourceCache->getDataResourceCount($physicalObjectTypeId));

        $statusCounts = $this->buildScopeQuery($scope, $physicalObjectTypeId, $filter)
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
     * @param  list<'curator'|'administrator'>  $allowedImprovementActors
     * @return list<array{
     *     id: int,
     *     doi: string|null,
     *     mainTitle: string,
     *     score: float,
     *     assessedAt: string|null,
     *     hasPendingSuggestions: bool,
     *     improvementOpportunity: array<string, mixed>
     * }>
     */
    private function buildAttentionList(
        string $scope,
        ?int $physicalObjectTypeId,
        array $allowedImprovementActors,
        bool $includeExternalResources = true,
        bool $includeDraftReviewResources = true,
        ?ResourceImpactFilter $filter = null,
    ): array {
        $query = $this->buildScopeQuery($scope, $physicalObjectTypeId, $filter)
            ->join('resource_assessments', 'resource_assessments.resource_id', '=', 'resources.id')
            ->where('resource_assessments.status', ResourceAssessment::STATUS_COMPLETED)
            ->whereNotNull('resource_assessments.total_score');

        if ($scope === RunResourceAssessmentsJob::RESOURCE_SCOPE && ! $includeExternalResources) {
            $query->whereDoesntHave(
                'landingPage',
                static fn (Builder $builder): Builder => $builder->where('template', 'external'),
            );
        }

        if ($scope === RunResourceAssessmentsJob::RESOURCE_SCOPE && ! $includeDraftReviewResources) {
            $this->resourceQueryBuilder->joinListingProjection($query);
            $this->resourceQueryBuilder->applyFilters($query, [
                'status' => ['curation', 'published'],
            ]);
        }

        $resources = $query
            ->with([
                'titles.titleType',
                'resourceAssessment',
                'landingPage.externalDomain',
                'landingPage.files',
                'landingPage.links',
                'igsnMetadata',
            ])
            ->orderBy('resource_assessments.total_score')
            ->orderBy('resources.id')
            ->select('resources.*')
            ->limit(10)
            ->get();
        $pendingSuggestionResourceIds = $scope === RunResourceAssessmentsJob::RESOURCE_SCOPE
            ? array_fill_keys($this->assistanceReviewService->resourceIdsWithPendingSuggestions(
                array_map(
                    static fn (int|string $id): int => (int) $id,
                    array_values($resources->modelKeys()),
                ),
            ), true)
            : [];

        $items = $resources
            ->map(function (Resource $resource) use ($scope, $allowedImprovementActors, $pendingSuggestionResourceIds): array {
                $assessment = $resource->resourceAssessment;
                $context = $this->fairImprovementContextFactory->fromResource(
                    resource: $resource,
                    assessedAt: $assessment?->assessed_at,
                    assessedIdentifier: $assessment?->assessed_identifier,
                );

                return [
                    'id' => $resource->id,
                    'doi' => $resource->doi,
                    'mainTitle' => $resource->main_title ?? 'Untitled',
                    'score' => round((float) ($assessment->total_score ?? 0), 2),
                    'assessedAt' => $assessment?->assessed_at?->toIso8601String(),
                    'hasPendingSuggestions' => isset($pendingSuggestionResourceIds[$resource->id]),
                    'improvementOpportunity' => $this->fairImprovementResolver->resolve(
                        payload: $assessment?->payload,
                        scope: $scope,
                        context: $context,
                        allowedActors: $allowedImprovementActors,
                    ),
                ];
            })
            ->values()
            ->all();

        /** @var list<array{
         *     id: int,
         *     doi: string|null,
         *     mainTitle: string,
         *     score: float,
         *     assessedAt: string|null,
         *     hasPendingSuggestions: bool,
         *     improvementOpportunity: array<string, mixed>
         * }> $items
         */
        return $items;
    }

    /**
     * @return Builder<Resource>
     */
    private function buildScopeQuery(
        string $scope,
        ?int $physicalObjectTypeId,
        ?ResourceImpactFilter $filter = null,
    ): Builder {
        $query = Resource::query();

        if ($scope === RunResourceAssessmentsJob::IGSN_SCOPE) {
            if ($physicalObjectTypeId === null) {
                return $query->whereRaw('1 = 0');
            }

            $query->where('resources.resource_type_id', $physicalObjectTypeId);
        } elseif ($physicalObjectTypeId !== null) {
            $query->where(function (Builder $builder) use ($physicalObjectTypeId): void {
                $builder->whereNull('resources.resource_type_id')
                    ->orWhere('resources.resource_type_id', '!=', $physicalObjectTypeId);
            });
        }

        if ($filter !== null) {
            $this->filterService->apply($query, $filter);
        }

        return $query;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function assessmentDatacenterOptions(): array
    {
        return array_values(Datacenter::query()
            ->whereHas('resources.resourceAssessment', static function (Builder $query): void {
                $query->where('status', ResourceAssessment::STATUS_COMPLETED);
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(static fn (Datacenter $datacenter): array => [
                'id' => $datacenter->id,
                'name' => $datacenter->name,
            ])
            ->all());
    }

    private function startScopeJob(AssessmentScope $scope): JsonResponse
    {
        $fujiUnavailableResponse = $this->fujiUnavailableResponse();

        if ($fujiUnavailableResponse !== null) {
            return $fujiUnavailableResponse;
        }

        $run = $this->assessmentRuns->startOrResume($scope, $this->authenticatedUser());

        return response()->json($this->assessmentRunPresenter->present($run));
    }

    /** @return array<string, mixed>|null */
    private function presentLatestRun(AssessmentScope $scope): ?array
    {
        $run = $this->assessmentRuns->latestForScope($scope);

        return $run === null ? null : $this->assessmentRunPresenter->present($run);
    }

    private function findRun(string $scope, string $jobId): ?AssessmentRun
    {
        $assessmentScope = AssessmentScope::tryFrom($scope);
        if ($assessmentScope === null) {
            return null;
        }

        return AssessmentRun::query()
            ->whereKey(strtolower($jobId))
            ->where('scope', $assessmentScope->value)
            ->first();
    }

    private function authenticatedUser(): User
    {
        $user = request()->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function fujiUnavailableResponse(): ?JsonResponse
    {
        $fujiHealth = $this->fujiService->healthStatus();

        if ($fujiHealth['healthy']) {
            return null;
        }

        return response()->json([
            'error' => $fujiHealth['message'] ?? 'F-UJI is configured but unhealthy.',
        ], 503);
    }

    /**
     * Returns the F-UJI health status, caching the result for 30 seconds to avoid
     * a blocking HTTP round-trip on every page load.
     *
     * @return array{healthy: bool, message: string|null, statusCode: int|null}
     */
    private function cachedHealthStatus(): array
    {
        /** @var array{healthy: bool, message: string|null, statusCode: int|null} */
        return Cache::remember(
            CacheKey::FUJI_HEALTH_STATUS->key(),
            30,
            fn (): array => $this->fujiService->healthStatus(),
        );
    }
}
