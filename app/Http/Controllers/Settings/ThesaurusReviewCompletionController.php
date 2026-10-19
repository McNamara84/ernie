<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreThesaurusReviewCompletionRequest;
use App\Models\User;
use App\Services\ThesaurusReviewReminderService;
use Illuminate\Http\JsonResponse;

final class ThesaurusReviewCompletionController extends Controller
{
    public function store(StoreThesaurusReviewCompletionRequest $request, ThesaurusReviewReminderService $reminders): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var array{period: string} $validated */
        $validated = $request->validated();
        $reminder = $reminders->complete($validated['period'], $user);

        if ($reminder['period'] !== $validated['period']) {
            return response()->json([
                'message' => 'The quarter has changed. Please review the current quarter before confirming again.',
                'thesaurusReviewReminder' => $reminder,
            ], 409);
        }

        return response()->json(['thesaurusReviewReminder' => $reminder]);
    }
}
