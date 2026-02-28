<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\UpdatePidJob;
use App\Models\PidSetting;
use App\Services\Pid4instStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Controller for managing PID (Persistent Identifier) settings via API.
 *
 * Provides endpoints for checking PID4INST update status
 * and triggering background update jobs.
 */
class PidSettingsController extends Controller
{
    public function __construct(
        private readonly Pid4instStatusService $statusService
    ) {}

    /**
     * Check for available updates by comparing local and remote instrument counts.
     *
     * POST /pid-settings/{type}/check
     *
     * @param  string  $type  The PID type (pid4inst)
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
            $comparison = $this->statusService->compareWithRemote();

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
     * Trigger a background job to update the PID instruments.
     *
     * POST /pid-settings/{type}/update
     *
     * Requires 'manage-thesauri' gate (admin only).
     *
     * @param  string  $type  The PID type (pid4inst)
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

        $cacheKey = UpdatePidJob::getCacheKey(strtolower($jobId));

        /** @var array{status: string, pidType: string, progress: string, startedAt?: string, completedAt?: string, failedAt?: string, error?: string}|null $status */
        $status = Cache::get($cacheKey);

        if ($status === null) {
            return response()->json([
                'error' => 'Job not found or expired',
            ], 404);
        }

        return response()->json($status);
    }
}
