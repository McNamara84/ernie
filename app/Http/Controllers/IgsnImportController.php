<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StartSingleIgsnImportRequest;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\Resource;
use App\Models\User;
use App\Services\IgsnImportService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Controller for IGSN import operations from DataCite.
 *
 * Handles starting imports and checking import status.
 * Reuses the 'importFromDataCite' policy from ResourcePolicy.
 */
class IgsnImportController extends Controller
{
    use AuthorizesRequests;

    /**
     * Start a new IGSN import job.
     */
    public function start(Request $request): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        $importId = Str::uuid()->toString();

        /** @var User $user */
        $user = $request->user();

        $this->initializeProgress(importId: $importId, total: 0);

        ImportIgsnsFromDataCiteJob::dispatch($user->id, $importId);

        return response()->json([
            'import_id' => $importId,
            'message' => 'IGSN import started successfully',
        ]);
    }

    /**
     * Start a new single IGSN import job.
     */
    public function startSingle(StartSingleIgsnImportRequest $request, IgsnImportService $importService): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        $doi = $request->getDoi();
        $handle = $request->getHandle();

        if ($importService->fetchSingleIgsn($doi) === null) {
            throw ValidationException::withMessages([
                'igsn' => 'This IGSN could not be found at DataCite.',
            ]);
        }

        $importId = Str::uuid()->toString();

        /** @var User $user */
        $user = $request->user();

        $this->initializeProgress(importId: $importId, total: 1, requestedIgsn: $handle);

        ImportIgsnsFromDataCiteJob::dispatch($user->id, $importId, $doi);

        return response()->json([
            'import_id' => $importId,
            'message' => 'Single IGSN import started successfully',
        ]);
    }

    /**
     * Get the status of an ongoing or completed IGSN import.
     */
    public function status(Request $request, string $importId): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        if (! Str::isUuid($importId)) {
            return response()->json(['error' => 'Invalid import ID format'], 400);
        }

        $progress = Cache::get("igsn_import:{$importId}");

        if ($progress === null) {
            return response()->json(['error' => 'Import not found'], 404);
        }

        return response()->json($progress);
    }

    /**
     * Cancel an ongoing IGSN import.
     */
    public function cancel(Request $request, string $importId): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        if (! Str::isUuid($importId)) {
            return response()->json(['error' => 'Invalid import ID format'], 400);
        }

        $progress = Cache::get("igsn_import:{$importId}");

        if ($progress === null) {
            return response()->json(['error' => 'Import not found'], 404);
        }

        if ($progress['status'] !== 'running' && $progress['status'] !== 'pending') {
            return response()->json(['error' => 'Import is not running'], 400);
        }

        Cache::put("igsn_import:{$importId}", array_merge($progress, [
            'status' => 'cancelled',
            'completed_at' => now()->toIso8601String(),
        ]), now()->addHours(24));

        return response()->json(['message' => 'Import cancelled']);
    }

    private function initializeProgress(string $importId, int $total, ?string $requestedIgsn = null): void
    {
        Cache::put("igsn_import:{$importId}", [
            'status' => 'pending',
            'total' => $total,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'enriched' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'requested_igsn' => $requestedIgsn,
            'discovered_children' => [],
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ], now()->addHours(24));
    }
}
