<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Assistance\AssistantRegistrar;
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
    ) {}

    /**
     * Display the Assistance page with suggestions from all registered assistants.
     */
    public function index(Request $request): Response
    {
        $perPage = max(1, min((int) $request->input('per_page', 25), 100));

        $sections = [];
        $manifests = [];

        foreach ($this->registrar->getAll() as $assistant) {
            $manifest = $assistant->getManifest();
            $manifests[] = $manifest->toArray();

            $sections[$manifest->id] = $assistant->loadSuggestions($perPage)->withQueryString();
        }

        return Inertia::render('assistance', [
            'sections' => $sections,
            'manifests' => $manifests,
        ]);
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
    public function accept(Request $request, int $suggestion): JsonResponse
    {
        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        $result = $assistant->acceptSuggestion($suggestion);

        return response()->json($result);
    }

    /**
     * Decline a suggestion from any assistant.
     */
    public function decline(Request $request, int $suggestion): JsonResponse
    {
        $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $assistantId = $request->route('assistantId');
        $assistant = $this->registrar->get((string) $assistantId);

        if ($assistant === null) {
            return response()->json(['error' => 'Unknown assistant.'], 404);
        }

        /** @var User $user */
        $user = $request->user();

        $assistant->declineSuggestion($suggestion, $user, $request->input('reason'));

        return response()->json(['success' => true]);
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
