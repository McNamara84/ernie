<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\UserGuidedTourAssignment;
use App\Services\GuidedTours\GuidedTourAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuidedTourAssignmentController extends Controller
{
    public function start(Request $request, UserGuidedTourAssignment $assignment, GuidedTourAssignmentService $guidedTourAssignmentService): JsonResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $updatedAssignment = $guidedTourAssignmentService->markStarted($assignment);

        return response()->json([
            'status' => $updatedAssignment->status,
            'completed' => $updatedAssignment->status === UserGuidedTourAssignment::STATUS_COMPLETED,
        ]);
    }

    public function close(Request $request, UserGuidedTourAssignment $assignment, GuidedTourAssignmentService $guidedTourAssignmentService): JsonResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $updatedAssignment = $guidedTourAssignmentService->markClosed($assignment);

        return response()->json([
            'status' => $updatedAssignment->status,
            'completed' => $updatedAssignment->status === UserGuidedTourAssignment::STATUS_COMPLETED,
        ]);
    }

    public function complete(Request $request, UserGuidedTourAssignment $assignment, GuidedTourAssignmentService $guidedTourAssignmentService): JsonResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $updatedAssignment = $guidedTourAssignmentService->markCompleted($assignment);

        return response()->json([
            'status' => $updatedAssignment->status,
            'completed' => $updatedAssignment->status === UserGuidedTourAssignment::STATUS_COMPLETED,
        ]);
    }

    private function authorizeAssignment(Request $request, UserGuidedTourAssignment $assignment): void
    {
        abort_unless($request->user()?->id === $assignment->user_id, 403);
    }
}