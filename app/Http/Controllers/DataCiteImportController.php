<?php

namespace App\Http\Controllers;

use App\Jobs\ImportFromDataCiteJob;
use App\Models\Resource;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Controller for DataCite import operations.
 *
 * Handles starting imports and checking import status.
 * All methods are protected by the 'importFromDataCite' policy.
 */
class DataCiteImportController extends Controller
{
    use AuthorizesRequests;

    /**
     * Start a new DataCite import job.
     *
     * Dispatches a background job to import all DOIs from DataCite.
     * Returns an import ID that can be used to track progress.
     */
    public function start(Request $request): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        $importId = Str::uuid()->toString();

        /** @var \App\Models\User $user */
        $user = $request->user();

        // Initialize progress in cache
        Cache::put("datacite_import:{$importId}", [
            'status' => 'pending',
            'total' => 0,
            'processed' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'skipped_dois' => [],
            'failed_dois' => [],
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ], now()->addHours(24));

        // Dispatch the import job
        ImportFromDataCiteJob::dispatch($user->id, $importId);

        return response()->json([
            'import_id' => $importId,
            'message' => 'Import started successfully',
        ]);
    }

    /**
     * Get the status of an ongoing or completed import.
     *
     * Returns progress information including counts of
     * imported, skipped, and failed DOIs.
     */
    public function status(Request $request, string $importId): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        // Validate UUID format
        if (! Str::isUuid($importId)) {
            return response()->json([
                'error' => 'Invalid import ID format',
            ], 400);
        }

        $progress = Cache::get("datacite_import:{$importId}");

        if ($progress === null) {
            return response()->json([
                'error' => 'Import not found',
            ], 404);
        }

        return response()->json($progress);
    }

    /**
     * Cancel an ongoing import.
     *
     * Note: This doesn't actually stop a running job,
     * but marks it as cancelled in the cache.
     * The job should check this status periodically.
     */
    public function cancel(Request $request, string $importId): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        // Validate UUID format
        if (! Str::isUuid($importId)) {
            return response()->json([
                'error' => 'Invalid import ID format',
            ], 400);
        }

        $progress = Cache::get("datacite_import:{$importId}");

        if ($progress === null) {
            return response()->json([
                'error' => 'Import not found',
            ], 404);
        }

        if ($progress['status'] !== 'running' && $progress['status'] !== 'pending') {
            return response()->json([
                'error' => 'Import is not running',
            ], 400);
        }

        Cache::put("datacite_import:{$importId}", array_merge($progress, [
            'status' => 'cancelled',
            'completed_at' => now()->toIso8601String(),
        ]), now()->addHours(24));

        return response()->json([
            'message' => 'Import cancelled',
        ]);
    }
}
