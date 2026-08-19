<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Editor\EditorLoadProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class EditorLoadProgressController extends Controller
{
    public function __construct(private readonly EditorLoadProgressService $tracker) {}

    public function status(Request $request, string $token): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $state = $this->tracker->findForUser($token, $user->id);

        abort_if($state === null, Response::HTTP_NOT_FOUND);

        return response()->json([
            'status' => $state['status'],
            'stage' => $state['stage'],
            'progress' => $state['progress'],
            'error' => $state['error'],
        ])->header('Cache-Control', 'no-store');
    }

    public function slow(Request $request, string $token): Response
    {
        $validated = $request->validate([
            'stage' => ['nullable', 'string', 'in:'.implode(',', EditorLoadProgressService::CLIENT_STAGES)],
            'progress' => ['nullable', 'integer', 'between:0,100'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $state = $this->tracker->findForUser($token, $user->id);

        abort_if($state === null, Response::HTTP_NOT_FOUND);

        $stage = is_string($validated['stage'] ?? null)
            ? $validated['stage']
            : (string) $state['stage'];
        $progress = is_int($validated['progress'] ?? null)
            ? $validated['progress']
            : (int) $state['progress'];

        $this->tracker->logIfSlow(
            $token,
            $user->id,
            (int) $state['resource_id'],
            $stage,
            $progress,
            'client',
        );

        return response()->noContent();
    }
}
