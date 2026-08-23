<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\BatchSuggestionValidationException;
use App\Http\Requests\Assistance\AcceptRorAffiliationMatchesRequest;
use App\Http\Requests\Assistance\AcceptSuggestionRequest;
use App\Http\Requests\Assistance\BatchSuggestionsRequest;
use App\Http\Requests\Assistance\DeclineSuggestionRequest;
use App\Http\Requests\Assistance\IndexAssistanceRequest;
use App\Models\RelationType;
use App\Models\User;
use App\Services\Assistance\AssistanceReviewService;
use App\Services\Assistance\AssistantRegistrar;
use App\Services\Assistance\BatchSuggestionActionService;
use App\Services\RorDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Thin orchestrator for the modular assistance system.
 *
 * All assistant-specific logic (queries, transforms, accept/decline) lives in
 * the individual module classes. This controller only handles HTTP concerns.
 */
class AssistanceController extends Controller
{
    public function __construct(
        private readonly AssistantRegistrar $registrar,
        private readonly AssistanceReviewService $reviewService,
        private readonly BatchSuggestionActionService $batchActionService,
        private readonly RorDiscoveryService $rorDiscoveryService,
    ) {}

    /**
     * Display the Assistance page with suggestions from all registered assistants.
     */
    public function index(IndexAssistanceRequest $request): Response
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));
        $filter = $request->resourceImpactFilter();
        $assistants = $this->registrar->getAll();
        $manifests = [];

        foreach ($assistants as $assistant) {
            $manifests[] = $assistant->getManifest()->toArray();
        }

        $review = $this->reviewService->build($request, $perPage, $filter);
        $user = $request->user();
        $savedCollapsedAssistantIds = $user instanceof User ? $user->assistance_collapsed_assistant_ids : null;
        $collapsedAssistantIds = is_array($savedCollapsedAssistantIds)
            ? array_values(array_intersect(array_keys($assistants), $savedCollapsedAssistantIds))
            : null;

        return Inertia::render('assistance', [
            ...$review,
            'filters' => $filter->toArray(),
            'manifests' => $manifests,
            'assistanceCollapsedAssistantIds' => $collapsedAssistantIds,
            'relationTypes' => $this->relationTypeOptions(),
        ]);
    }

    /**
     * @return list<array{id: int, name: string, slug: string, usage_count: int, is_most_used: bool}>
     */
    private function relationTypeOptions(): array
    {
        $ranked = RelationType::query()
            ->select(['relation_types.id', 'relation_types.name', 'relation_types.slug'])
            ->active()
            ->withCount('relatedIdentifiers')
            ->orderByDesc('related_identifiers_count')
            ->orderBy('name')
            ->get();

        $mostUsed = $ranked->take(5);
        $mostUsedIds = array_fill_keys($mostUsed->pluck('id')->all(), true);
        $remaining = $ranked->skip(5)
            ->sortBy(fn (RelationType $type): string => mb_strtolower($type->name))
            ->values();

        return array_values($mostUsed
            ->concat($remaining)
            ->map(fn (RelationType $type): array => [
                'id' => $type->id,
                'name' => $type->name,
                'slug' => $type->slug,
                'usage_count' => (int) $type->getAttribute('related_identifiers_count'),
                'is_most_used' => isset($mostUsedIds[$type->id]),
            ])
            ->values()
            ->all());
    }

    /**
     * Start discovery for a single assistant.
     */
    public function check(Request $request): JsonResponse
    {
        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        $lock = Cache::lock($assistant->getLockKey(), 7200);

        if (! $lock->get()) {
            return response()->json([
                'error' => $assistant->getManifest()->statusLabels['already_running']
                    ?? 'A discovery job is already running.',
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        try {
            Cache::put($assistant->getJobStatusCacheKey($jobId), [
                'status' => 'queued',
                'progress' => 'Waiting to start...',
                'startedAt' => now()->toIso8601String(),
                'lockOwner' => $lock->owner(),
            ], now()->addHours(2));

            $assistant->dispatchDiscovery($jobId, $lock->owner());
        } catch (\Throwable $e) {
            $lock->release();
            Cache::forget($assistant->getJobStatusCacheKey($jobId));

            throw $e;
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Poll the status of a running discovery job.
     */
    public function status(Request $request, string $jobId): JsonResponse
    {
        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        $cacheKey = $assistant->getJobStatusCacheKey($jobId);

        /** @var array<string, mixed>|null $status */
        $status = Cache::get($cacheKey);

        if ($status === null) {
            return response()->json([
                'status' => 'unknown',
                'progress' => 'Job not found.',
            ], 404);
        }

        unset($status['lockOwner']);

        return response()->json($status);
    }

    /**
     * Accept a suggestion from any assistant.
     */
    public function accept(AcceptSuggestionRequest $request, int $suggestion): JsonResponse
    {
        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        $result = $assistant->acceptSuggestion($suggestion, $request->validated());

        return response()->json($result);
    }

    public function batchAccept(BatchSuggestionsRequest $request): JsonResponse
    {
        return $this->batchAction($request, 'accept');
    }

    public function batchDecline(BatchSuggestionsRequest $request): JsonResponse
    {
        return $this->batchAction($request, 'decline');
    }

    /**
     * @param  'accept'|'decline'  $action
     */
    private function batchAction(BatchSuggestionsRequest $request, string $action): JsonResponse
    {
        $validated = $request->validated();

        /** @var User $user */
        $user = $request->user();

        try {
            $result = $this->batchActionService->execute(
                action: $action,
                resourceId: (int) $validated['resource_id'],
                selections: $validated['suggestions'],
                user: $user,
                reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            );
        } catch (BatchSuggestionValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }

    /**
     * Accept further exact creator-affiliation matches for an accepted ROR suggestion.
     */
    public function acceptRorAffiliationMatches(AcceptRorAffiliationMatchesRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->rorDiscoveryService->acceptMatchingAffiliationRors((string) $validated['bulk_token']);
        $statusCode = match (true) {
            $result['success'] => 200,
            $result['retryable'] ?? false => 500,
            default => 422,
        };

        return response()->json($result, $statusCode);
    }

    /**
     * Decline a suggestion from any assistant.
     */
    public function decline(DeclineSuggestionRequest $request, int $suggestion): JsonResponse
    {
        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        /** @var User $user */
        $user = $request->user();

        $result = $assistant->declineSuggestion($suggestion, $user, $request->input('reason'));

        return response()->json($result);
    }

    /**
     * Start discovery for ALL registered assistants simultaneously.
     */
    public function checkAll(): JsonResponse
    {
        $result = [];

        foreach ($this->registrar->getAll() as $assistant) {
            $id = $assistant->getId();
            $lock = Cache::lock($assistant->getLockKey(), 7200);

            if (! $lock->get()) {
                $result["{$id}Error"] = $assistant->getManifest()->statusLabels['already_running']
                    ?? 'A discovery job is already running.';

                continue;
            }

            $jobId = Str::uuid()->toString();

            try {
                Cache::put($assistant->getJobStatusCacheKey($jobId), [
                    'status' => 'queued',
                    'progress' => 'Waiting to start...',
                    'startedAt' => now()->toIso8601String(),
                    'lockOwner' => $lock->owner(),
                ], now()->addHours(2));

                $assistant->dispatchDiscovery($jobId, $lock->owner());
                $result["{$id}JobId"] = $jobId;
            } catch (\Throwable $e) {
                $lock->release();
                Cache::forget($assistant->getJobStatusCacheKey($jobId));

                report($e);
                $result["{$id}Error"] = "{$assistant->getName()} could not be started.";
            }
        }

        $hasJobIds = collect($result)->keys()->contains(fn (string $k) => str_ends_with($k, 'JobId'));

        if (! $hasJobIds) {
            return response()->json([
                ...$result,
                'error' => 'All discovery jobs are already running. Please wait for them to finish.',
            ], 409);
        }

        return response()->json($result);
    }
}
