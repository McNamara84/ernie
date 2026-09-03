<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\StoreUserFeedbackRequest;
use App\Mail\UserFeedbackMail;
use App\Models\User;
use App\Support\FeedbackDiagnosticSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class UserFeedbackController extends Controller
{
    public function __construct(
        private readonly FeedbackDiagnosticSanitizer $sanitizer,
    ) {}

    public function __invoke(StoreUserFeedbackRequest $request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $admins = User::query()
            ->active()
            ->role(UserRole::ADMIN)
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        if ($admins->isEmpty()) {
            Log::warning('User feedback could not be queued because no active administrators exist', [
                'submitted_by_user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Feedback cannot be submitted right now because no administrator is available.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $feedbackId = (string) Str::uuid();
        $submittedAt = CarbonImmutable::now('UTC')->toIso8601String();
        $data = $request->feedbackData();
        $diagnostics = array_map(function (array $event): array {
            if (array_key_exists('message', $event)) {
                $event['message'] = $this->sanitizer->message(is_string($event['message']) ? $event['message'] : null);
            }

            return $event;
        }, $data['diagnostics']);

        try {
            foreach ($admins as $admin) {
                Mail::to($admin->email, $admin->name)->queue(new UserFeedbackMail(
                    feedbackId: $feedbackId,
                    category: $data['category'],
                    feedbackMessage: $data['message'],
                    submittedByName: $user->name,
                    submittedByEmail: $user->email,
                    submittedByRole: $user->role->label(),
                    submittedAt: $submittedAt,
                    userAgent: $this->sanitizer->userAgent($request->userAgent()),
                    page: $data['page'],
                    environment: $data['environment'],
                    diagnostics: $diagnostics,
                    recipientAdminId: $admin->id,
                    recipientName: $admin->name,
                ));
            }
        } catch (Throwable $exception) {
            Log::error('User feedback queue dispatch failed', [
                'feedback_id' => $feedbackId,
                'submitted_by_user_id' => $user->id,
                'recipient_count' => $admins->count(),
                'exception' => $exception::class,
                'error' => Str::limit($exception->getMessage(), 500, ''),
            ]);

            return response()->json([
                'message' => 'Feedback could not be submitted. Please try again.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        Log::info('User feedback queued', [
            'feedback_id' => $feedbackId,
            'submitted_by_user_id' => $user->id,
            'recipient_count' => $admins->count(),
        ]);

        return response()->json([
            'message' => 'Feedback submitted.',
            'feedback_id' => $feedbackId,
        ], Response::HTTP_ACCEPTED);
    }
}
