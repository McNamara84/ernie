<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\UpdateThesaurusJob;
use App\Models\ThesaurusSetting;
use App\Services\ThesaurusStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Controller for managing GCMD thesaurus settings via API.
 *
 * Provides endpoints for listing thesauri, checking for updates,
 * and triggering background update jobs.
 */
class ThesaurusSettingsController extends Controller
{
    public function __construct(
        private readonly ThesaurusStatusService $statusService
    ) {}

    /**
     * List all thesauri with their current status.
     *
     * GET /api/v1/thesauri
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $thesauri = ThesaurusSetting::all()->map(function (ThesaurusSetting $thesaurus) {
            $localStatus = $this->statusService->getLocalStatus($thesaurus);

            return [
                'type' => $thesaurus->type,
                'displayName' => $thesaurus->display_name,
                'isActive' => $thesaurus->is_active,
                'isElmoActive' => $thesaurus->is_elmo_active,
                'exists' => $localStatus['exists'],
                'conceptCount' => $localStatus['conceptCount'],
                'lastUpdated' => $localStatus['lastUpdated'],
            ];
        });

        return response()->json($thesauri);
    }

    /**
     * Check for available updates by comparing local and remote concept counts.
     *
     * POST /api/v1/thesauri/{type}/check
     *
     * @param  string  $type  The thesaurus type (science_keywords, platforms, instruments)
     * @return JsonResponse
     */
    public function checkStatus(string $type): JsonResponse
    {
        $thesaurus = ThesaurusSetting::where('type', $type)->first();

        if ($thesaurus === null) {
            return response()->json([
                'error' => "Thesaurus type '{$type}' not found",
            ], 404);
        }

        try {
            $comparison = $this->statusService->compareWithRemote($thesaurus);

            return response()->json([
                'type' => $type,
                'displayName' => $thesaurus->display_name,
                'localCount' => $comparison['localCount'],
                'remoteCount' => $comparison['remoteCount'],
                'updateAvailable' => $comparison['updateAvailable'],
                'lastUpdated' => $comparison['lastUpdated'],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'error' => 'Failed to check remote status: '.$e->getMessage(),
            ], 503);
        }
    }

    /**
     * Trigger a background job to update the thesaurus.
     *
     * POST /api/v1/thesauri/{type}/update
     *
     * Requires 'manage-thesauri' gate (admin only).
     *
     * @param  string  $type  The thesaurus type (science_keywords, platforms, instruments)
     * @return JsonResponse
     */
    public function triggerUpdate(string $type): JsonResponse
    {
        // Authorization check
        if (Gate::denies('manage-thesauri')) {
            return response()->json([
                'error' => 'Unauthorized. Only administrators can trigger thesaurus updates.',
            ], 403);
        }

        $thesaurus = ThesaurusSetting::where('type', $type)->first();

        if ($thesaurus === null) {
            return response()->json([
                'error' => "Thesaurus type '{$type}' not found",
            ], 404);
        }

        // Validate thesaurus type for the job
        if (! in_array($type, ThesaurusSetting::getValidTypes(), true)) {
            return response()->json([
                'error' => "Invalid thesaurus type: {$type}",
            ], 400);
        }

        $jobId = Str::uuid()->toString();

        UpdateThesaurusJob::dispatch($type, $jobId);

        return response()->json([
            'jobId' => $jobId,
            'type' => $type,
            'displayName' => $thesaurus->display_name,
            'message' => 'Update job started',
        ]);
    }

    /**
     * Get the status of an update job.
     *
     * GET /api/v1/thesauri/update-status/{jobId}
     *
     * @param  string  $jobId  The UUID of the update job
     * @return JsonResponse
     */
    public function updateStatus(string $jobId): JsonResponse
    {
        // Validate UUID format
        if (! Str::isUuid($jobId)) {
            return response()->json([
                'error' => 'Invalid job ID format',
            ], 400);
        }

        $cacheKey = UpdateThesaurusJob::getCacheKey(strtolower($jobId));

        /** @var array{status: string, thesaurusType: string, progress: string, startedAt?: string, completedAt?: string, failedAt?: string, error?: string}|null $status */
        $status = Cache::get($cacheKey);

        if ($status === null) {
            return response()->json([
                'error' => 'Job not found or expired',
            ], 404);
        }

        return response()->json($status);
    }
}
