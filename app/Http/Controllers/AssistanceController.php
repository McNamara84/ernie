<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DiscoverOrcidsJob;
use App\Jobs\DiscoverRelationsJob;
use App\Jobs\DiscoverRorsJob;
use App\Models\SuggestedOrcid;
use App\Models\SuggestedRelation;
use App\Models\SuggestedRor;
use App\Models\User;
use App\Services\OrcidDiscoveryService;
use App\Services\RelationDiscoveryService;
use App\Services\RorDiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AssistanceController extends Controller
{
    public function __construct(
        private readonly RelationDiscoveryService $discoveryService,
        private readonly OrcidDiscoveryService $orcidDiscoveryService,
        private readonly RorDiscoveryService $rorDiscoveryService,
    ) {}

    /**
     * Display the Assistance page with pending suggested relations and ORCIDs.
     */
    public function index(Request $request): Response
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        $paginator = SuggestedRelation::with(['resource.titles.titleType', 'identifierType', 'relationType'])
            ->orderBy('discovered_at', 'desc')
            ->paginate($perPage)
            ->withQueryString();

        $suggestions = $paginator->through(fn (SuggestedRelation $s) => [
            'id' => $s->id,
            'resource_id' => $s->resource_id,
            'resource_doi' => $s->resource->doi ?? '',
            'resource_title' => $s->resource->mainTitle ?? 'Untitled',
            'identifier' => $s->identifier,
            'identifier_type' => $s->identifierType->slug ?? '',
            'identifier_type_name' => $s->identifierType->name ?? '',
            'relation_type' => $s->relationType->slug ?? '',
            'relation_type_name' => $s->relationType->name ?? '',
            'source' => $s->source,
            'source_title' => $s->source_title,
            'source_type' => $s->source_type,
            'source_publisher' => $s->source_publisher,
            'source_publication_date' => $s->source_publication_date,
            'discovered_at' => $s->discovered_at->toIso8601String(),
        ]);

        // ORCID suggestions: ordered by enrichable count per resource, then similarity
        $enrichableCounts = SuggestedOrcid::selectRaw('resource_id, COUNT(DISTINCT person_id) as enrichable_count')
            ->groupBy('resource_id');

        $orcidPaginator = SuggestedOrcid::with(['resource.titles.titleType', 'person'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_orcids.resource_id', '=', 'enrichable_counts.resource_id')
            ->select('suggested_orcids.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_orcids.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'orcid_page')
            ->withQueryString();

        $orcidSuggestions = $orcidPaginator->through(function (SuggestedOrcid $s) use ($orcidPaginator) {
            // Bulk-load affiliations for all person IDs on the current page (cached per request)
            static $affiliationCache = null;
            if ($affiliationCache === null) {
                $personIds = $orcidPaginator->getCollection()->pluck('person_id')->unique()->values()->all();
                $affiliationCache = $this->orcidDiscoveryService->loadPersonAffiliations($personIds);
            }

            $person = $s->person;
            $personAffiliations = $affiliationCache[$s->person_id] ?? [];

            return [
                'id' => $s->id,
                'resource_id' => $s->resource_id,
                'resource_doi' => $s->resource->doi ?? '',
                'resource_title' => $s->resource->mainTitle ?? 'Untitled',
                'person_id' => $s->person_id,
                'person_name' => $person->full_name,
                'person_affiliations' => $personAffiliations,
                'source_context' => $s->source_context,
                'suggested_orcid' => $s->suggested_orcid,
                'similarity_score' => $s->similarity_score,
                'candidate_first_name' => $s->candidate_first_name ?? '',
                'candidate_last_name' => $s->candidate_last_name ?? '',
                'candidate_affiliations' => $s->candidate_affiliations ?? [],
                'discovered_at' => $s->discovered_at->toIso8601String(),
            ];
        });

        return Inertia::render('assistance', [
            'suggestions' => $suggestions,
            'orcidSuggestions' => $orcidSuggestions,
            'rorSuggestions' => $this->loadRorSuggestions($perPage),
        ]);
    }

    /**
     * Trigger a relation discovery check for all registered DOIs.
     *
     * Uses a cache lock to prevent concurrent discovery runs.
     */
    public function check(): JsonResponse
    {
        $lock = Cache::lock('relation_discovery_running', 7200);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'A discovery job is already running. Please wait for it to finish.',
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        try {
            Cache::put(DiscoverRelationsJob::getCacheKey($jobId), [
                'status' => 'queued',
                'progress' => 'Waiting to start...',
                'startedAt' => now()->toIso8601String(),
                'lockOwner' => $lock->owner(),
            ], now()->addHours(2));

            DiscoverRelationsJob::dispatch($jobId, $lock->owner());
        } catch (\Throwable $e) {
            $lock->release();
            Cache::forget(DiscoverRelationsJob::getCacheKey($jobId));

            throw $e;
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Get the status of a running discovery job.
     */
    public function status(string $jobId): JsonResponse
    {
        $cacheKey = DiscoverRelationsJob::getCacheKey($jobId);

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
     * Accept a suggested relation.
     */
    public function accept(SuggestedRelation $suggestion): JsonResponse
    {
        $result = $this->discoveryService->acceptRelation($suggestion);

        return response()->json($result);
    }

    /**
     * Decline a suggested relation.
     */
    public function decline(Request $request, SuggestedRelation $suggestion): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->discoveryService->declineRelation(
            $suggestion,
            $user,
            $request->input('reason'),
        );

        return response()->json(['success' => true]);
    }

    /**
     * Trigger relation, ORCID, and ROR discovery jobs simultaneously.
     */
    public function checkAll(): JsonResponse
    {
        $result = [];

        // Start relation discovery
        $relationLock = Cache::lock('relation_discovery_running', 7200);
        if ($relationLock->get()) {
            $relationJobId = Str::uuid()->toString();

            try {
                Cache::put(DiscoverRelationsJob::getCacheKey($relationJobId), [
                    'status' => 'queued',
                    'progress' => 'Waiting to start...',
                    'startedAt' => now()->toIso8601String(),
                    'lockOwner' => $relationLock->owner(),
                ], now()->addHours(2));

                DiscoverRelationsJob::dispatch($relationJobId, $relationLock->owner());
                $result['relationJobId'] = $relationJobId;
            } catch (\Throwable $e) {
                $relationLock->release();
                Cache::forget(DiscoverRelationsJob::getCacheKey($relationJobId));

                report($e);
                $result['relationError'] = 'Relation discovery could not be started.';
            }
        }

        // Start ORCID discovery
        $orcidLock = Cache::lock('orcid_discovery_running', 7200);
        if ($orcidLock->get()) {
            $orcidJobId = Str::uuid()->toString();

            try {
                Cache::put(DiscoverOrcidsJob::getCacheKey($orcidJobId), [
                    'status' => 'queued',
                    'progress' => 'Waiting to start...',
                    'startedAt' => now()->toIso8601String(),
                    'lockOwner' => $orcidLock->owner(),
                ], now()->addHours(2));

                DiscoverOrcidsJob::dispatch($orcidJobId, $orcidLock->owner());
                $result['orcidJobId'] = $orcidJobId;
            } catch (\Throwable $e) {
                $orcidLock->release();
                Cache::forget(DiscoverOrcidsJob::getCacheKey($orcidJobId));

                report($e);
                $result['orcidError'] = 'ORCID discovery could not be started.';
            }
        }

        // Start ROR discovery
        $rorLock = Cache::lock('ror_discovery_running', 7200);
        if ($rorLock->get()) {
            $rorJobId = Str::uuid()->toString();

            try {
                Cache::put(DiscoverRorsJob::getCacheKey($rorJobId), [
                    'status' => 'queued',
                    'progress' => 'Waiting to start...',
                    'startedAt' => now()->toIso8601String(),
                    'lockOwner' => $rorLock->owner(),
                ], now()->addHours(2));

                DiscoverRorsJob::dispatch($rorJobId, $rorLock->owner());
                $result['rorJobId'] = $rorJobId;
            } catch (\Throwable $e) {
                $rorLock->release();
                Cache::forget(DiscoverRorsJob::getCacheKey($rorJobId));

                report($e);
                $result['rorError'] = 'ROR discovery could not be started.';
            }
        }

        if (empty($result)) {
            return response()->json([
                'error' => 'All discovery jobs are already running. Please wait for them to finish.',
            ], 409);
        }

        return response()->json($result);
    }

    /**
     * Trigger ORCID discovery only.
     */
    public function checkOrcids(): JsonResponse
    {
        $lock = Cache::lock('orcid_discovery_running', 7200);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'An ORCID discovery job is already running. Please wait for it to finish.',
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        try {
            Cache::put(DiscoverOrcidsJob::getCacheKey($jobId), [
                'status' => 'queued',
                'progress' => 'Waiting to start...',
                'startedAt' => now()->toIso8601String(),
                'lockOwner' => $lock->owner(),
            ], now()->addHours(2));

            DiscoverOrcidsJob::dispatch($jobId, $lock->owner());
        } catch (\Throwable $e) {
            $lock->release();
            Cache::forget(DiscoverOrcidsJob::getCacheKey($jobId));

            throw $e;
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Get the status of a running ORCID discovery job.
     */
    public function orcidStatus(string $jobId): JsonResponse
    {
        $cacheKey = DiscoverOrcidsJob::getCacheKey($jobId);

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
     * Accept a suggested ORCID.
     */
    public function acceptOrcid(SuggestedOrcid $suggestion): JsonResponse
    {
        $result = $this->orcidDiscoveryService->acceptOrcid($suggestion);

        return response()->json($result);
    }

    /**
     * Decline a suggested ORCID.
     */
    public function declineOrcid(Request $request, SuggestedOrcid $suggestion): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->orcidDiscoveryService->declineOrcid(
            $suggestion,
            $user,
            $request->input('reason'),
        );

        return response()->json(['success' => true]);
    }

    /**
     * Trigger ROR discovery only.
     */
    public function checkRors(): JsonResponse
    {
        $lock = Cache::lock('ror_discovery_running', 7200);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'A ROR discovery job is already running. Please wait for it to finish.',
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        try {
            Cache::put(DiscoverRorsJob::getCacheKey($jobId), [
                'status' => 'queued',
                'progress' => 'Waiting to start...',
                'startedAt' => now()->toIso8601String(),
                'lockOwner' => $lock->owner(),
            ], now()->addHours(2));

            DiscoverRorsJob::dispatch($jobId, $lock->owner());
        } catch (\Throwable $e) {
            $lock->release();
            Cache::forget(DiscoverRorsJob::getCacheKey($jobId));

            throw $e;
        }

        return response()->json(['jobId' => $jobId]);
    }

    /**
     * Get the status of a running ROR discovery job.
     */
    public function rorStatus(string $jobId): JsonResponse
    {
        $cacheKey = DiscoverRorsJob::getCacheKey($jobId);

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
     * Accept a suggested ROR-ID.
     */
    public function acceptRor(SuggestedRor $suggestion): JsonResponse
    {
        $result = $this->rorDiscoveryService->acceptRor($suggestion);

        return response()->json($result);
    }

    /**
     * Decline a suggested ROR-ID.
     */
    public function declineRor(Request $request, SuggestedRor $suggestion): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->rorDiscoveryService->declineRor(
            $suggestion,
            $user,
            $request->input('reason'),
        );

        return response()->json(['success' => true]);
    }

    /**
     * Load paginated ROR suggestions ordered by similarity score.
     *
     * @return \Illuminate\Pagination\LengthAwarePaginator<int, array{id: int, resource_id: int, resource_doi: string, resource_title: string, entity_type: string, entity_id: int, entity_name: string, suggested_ror_id: string, suggested_name: string, similarity_score: float, ror_aliases: array<int, string>, existing_identifier: string|null, existing_identifier_type: string|null, discovered_at: string}>
     */
    private function loadRorSuggestions(int $perPage): \Illuminate\Pagination\LengthAwarePaginator
    {
        $enrichableCounts = SuggestedRor::selectRaw('resource_id, COUNT(*) as enrichable_count')
            ->groupBy('resource_id');

        return SuggestedRor::with(['resource.titles.titleType'])
            ->joinSub($enrichableCounts, 'enrichable_counts', 'suggested_rors.resource_id', '=', 'enrichable_counts.resource_id')
            ->select('suggested_rors.*', 'enrichable_counts.enrichable_count')
            ->orderByDesc('enrichable_counts.enrichable_count')
            ->orderByDesc('suggested_rors.similarity_score')
            ->paginate(perPage: $perPage, pageName: 'ror_page')
            ->withQueryString()
            ->through(fn (SuggestedRor $s) => [
                'id' => $s->id,
                'resource_id' => $s->resource_id,
                'resource_doi' => $s->resource->doi ?? '',
                'resource_title' => $s->resource->mainTitle ?? 'Untitled',
                'entity_type' => $s->entity_type,
                'entity_id' => $s->entity_id,
                'entity_name' => $s->entity_name,
                'suggested_ror_id' => $s->suggested_ror_id,
                'suggested_name' => $s->suggested_name,
                'similarity_score' => $s->similarity_score,
                'ror_aliases' => $s->ror_aliases ?? [],
                'existing_identifier' => $s->existing_identifier,
                'existing_identifier_type' => $s->existing_identifier_type,
                'discovered_at' => $s->discovered_at->toIso8601String(),
            ]);
    }
}
