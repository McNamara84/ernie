<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Services\Pid4instStatusService;
use App\Services\RorStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Controller for managing PID (Persistent Identifier) settings via API.
 *
 * Provides endpoints for checking PID update status
 * and triggering background update jobs.
 */
class PidSettingsController extends Controller
{
    public function __construct(
        private readonly Pid4instStatusService $pid4instStatusService,
        private readonly RorStatusService $rorStatusService,
    ) {}

    /**
     * Check for available updates by comparing local and remote counts.
     *
     * POST /pid-settings/{type}/check
     *
     * @param  string  $type  The PID type (pid4inst, ror)
     */
    public function checkStatus(string $type): JsonResponse
    {
        $setting = PidSetting::where('type', $type)->first();

        if ($setting === null) {
            return response()->json([
                'error' => "PID type '{$type}' not found",
            ], 404);
        }

        try {
            $statusService = $this->resolveStatusService($type);

            if ($statusService === null) {
                return response()->json([
                    'error' => "No status service available for PID type '{$type}'",
                ], 400);
            }

            $comparison = $statusService->compareWithRemote($setting);

            return response()->json([
                'type' => $type,
                'displayName' => $setting->display_name,
                'localCount' => $comparison['localCount'],
                'remoteCount' => $comparison['remoteCount'],
                'updateAvailable' => $comparison['updateAvailable'],
                'lastUpdated' => $comparison['lastUpdated'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Failed to check remote status: ' . $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Trigger a background job to update the PID data.
     *
     * POST /pid-settings/{type}/update
     *
     * Requires 'manage-thesauri' gate (admin only).
     *
     * @param  string  $type  The PID type (pid4inst, ror)
     */
    public function triggerUpdate(string $type): JsonResponse
    {
        if (Gate::denies('manage-thesauri')) {
            return response()->json([
                'error' => 'Unauthorized. Only administrators can trigger PID updates.',
            ], 403);
        }

        $setting = PidSetting::where('type', $type)->first();

        if ($setting === null) {
            return response()->json([
                'error' => "PID type '{$type}' not found",
            ], 404);
        }

        if (! in_array($type, PidSetting::getValidTypes(), true)) {
            return response()->json([
                'error' => "Invalid PID type: {$type}",
            ], 400);
        }

        $jobId = Str::uuid()->toString();

        // Pre-populate cache with 'running' status before dispatching the job.
        // This prevents a race condition where the frontend polls update-status
        // before the queue worker has started the job (which would return 404).
        $cacheKey = UpdatePidJob::getCacheKey($jobId);
        Cache::put($cacheKey, [
            'status' => 'running',
            'pidType' => $type,
            'progress' => 'Queued, waiting for worker...',
            'startedAt' => now()->toIso8601String(),
        ], now()->addHours(1));

        UpdatePidJob::dispatch($type, $jobId);

        return response()->json([
            'jobId' => $jobId,
            'type' => $type,
            'displayName' => $setting->display_name,
            'message' => 'Update job started',
        ]);
    }

    /**
     * Get the status of an update job.
     *
     * GET /pid-settings/update-status/{jobId}
     *
     * @param  string  $jobId  The UUID of the update job
     */
    public function updateStatus(string $jobId): JsonResponse
    {
        if (! Str::isUuid($jobId)) {
            return response()->json([
                'error' => 'Invalid job ID format',
            ], 400);
        }

        $cacheKey = UpdatePidJob::getCacheKey($jobId);

        /** @var array{status: string, pidType: string, progress: string, startedAt?: string, completedAt?: string, failedAt?: string, error?: string}|null $status */
        $status = Cache::get($cacheKey);

        if ($status === null) {
            return response()->json([
                'error' => 'Job not found or expired',
            ], 404);
        }

        return response()->json($status);
    }

    /**
     * Resolve the appropriate status service for the given PID type.
     */
    private function resolveStatusService(string $type): Pid4instStatusService|RorStatusService|null
    {
        return match ($type) {
            PidSetting::TYPE_PID4INST => $this->pid4instStatusService,
            PidSetting::TYPE_ROR => $this->rorStatusService,
            default => null,
        };
    }
}
