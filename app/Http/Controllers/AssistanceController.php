<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\DiscoverRelationsJob;
use App\Models\SuggestedRelation;
use App\Services\RelationDiscoveryService;
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
    ) {}

    /**
     * Display the Assistance page with pending suggested relations.
     */
    public function index(Request $request): Response
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        $paginator = SuggestedRelation::with(['resource.titles.titleType', 'identifierType', 'relationType'])
            ->orderBy('discovered_at', 'desc')
            ->paginate($perPage);

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

        return Inertia::render('assistance', [
            'suggestions' => $suggestions,
        ]);
    }

    /**
     * Trigger a relation discovery check for all registered DOIs.
     *
     * Uses a cache lock to prevent concurrent discovery runs.
     */
    public function check(): JsonResponse
    {
        $lock = Cache::lock('relation_discovery_running', 3600);

        if (! $lock->get()) {
            return response()->json([
                'error' => 'A discovery job is already running. Please wait for it to finish.',
            ], 409);
        }

        $jobId = Str::uuid()->toString();

        Cache::put(DiscoverRelationsJob::getCacheKey($jobId), [
            'status' => 'queued',
            'progress' => 'Waiting to start...',
            'startedAt' => now()->toIso8601String(),
            'lockOwner' => $lock->owner(),
        ], now()->addHours(2));

        DiscoverRelationsJob::dispatch($jobId, $lock->owner());

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

        /** @var \App\Models\User $user */
        $user = $request->user();

        $this->discoveryService->declineRelation(
            $suggestion,
            $user,
            $request->input('reason'),
        );

        return response()->json(['success' => true]);
    }
}
