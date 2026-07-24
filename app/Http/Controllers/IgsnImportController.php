<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StartDatacenterIgsnImportRequest;
use App\Http\Requests\StartSingleIgsnImportRequest;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\Resource;
use App\Models\User;
use App\Services\IgsnImportService;
use App\Services\LegacyIgsnPortalService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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
     * List the legacy datacenters available for grouped IGSN imports.
     */
    public function datacenters(LegacyIgsnPortalService $portalService): JsonResponse
    {
        $this->authorize('importFromDataCite', Resource::class);

        try {
            $datacenters = $portalService->listDatacenters();
        } catch (\RuntimeException $exception) {
            Log::warning('Unable to load legacy IGSN datacenters', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The legacy IGSN portal is currently unavailable. Please try again later.',
            ], 503);
        }

        return response()->json(['datacenters' => $datacenters]);
    }

    /**
     * Start an import limited to one legacy IGSN datacenter.
     */
    public function startDatacenter(
        StartDatacenterIgsnImportRequest $request,
        LegacyIgsnPortalService $portalService,
    ): JsonResponse {
        $this->authorize('importFromDataCite', Resource::class);

        $legacyDatacenterId = $request->getDatacenterId();

        try {
            $datacenter = $portalService->findDatacenter($legacyDatacenterId);
        } catch (\RuntimeException $exception) {
            Log::warning('Unable to verify legacy IGSN datacenter', [
                'legacy_datacenter_id' => $legacyDatacenterId,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => 'The legacy IGSN portal is currently unavailable. Please try again later.',
            ], 503);
        }

        if ($datacenter === null) {
            throw ValidationException::withMessages([
                'datacenter_id' => ['The selected legacy IGSN datacenter is no longer available.'],
            ]);
        }

        $importId = Str::uuid()->toString();
        /** @var User $user */
        $user = $request->user();

        $this->initializeProgress(
            importId: $importId,
            total: $datacenter['resource_count'],
            datacenter: $datacenter,
        );

        ImportIgsnsFromDataCiteJob::dispatch($user->id, $importId, null, $legacyDatacenterId);

        return response()->json([
            'import_id' => $importId,
            'message' => 'Datacenter IGSN import started successfully',
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

        try {
            $igsnRecord = $importService->fetchSingleIgsn($doi);
        } catch (RequestException|\RuntimeException $e) {
            Log::warning('Unable to verify single IGSN at DataCite before import', [
                'doi' => $doi,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'DataCite is currently unavailable. Please try again later.',
            ], 503);
        }

        if ($igsnRecord === null) {
            throw ValidationException::withMessages([
                'igsn' => ['This IGSN could not be found at DataCite.'],
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

    /**
     * @param  array{id: string, name: string, legacy_name: string, resource_count: int}|null  $datacenter
     */
    private function initializeProgress(
        string $importId, int $total, ?string $requestedIgsn = null, ?array $datacenter = null
    ): void {
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
            'datacenter' => $datacenter,
            'unassigned' => 0,
            'unassigned_dois' => [],
            'warnings' => [],
            'started_at' => now()->toIso8601String(),
            'completed_at' => null,
        ], now()->addHours(24));
    }
}
