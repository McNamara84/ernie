<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FeedbackCategory;
use App\Mail\UserFeedbackMail;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class SendUserFeedbackEmail implements ShouldBeEncrypted, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * @param  array{path: string, title: string}  $page
     * @param  array{appearance: string, resolved_theme: string, viewport_width: int, viewport_height: int, device_pixel_ratio: float|int, locale: string, timezone: string}  $environment
     * @param  list<array<string, int|string|null>>  $diagnostics
     */
    public function __construct(
        public readonly string $feedbackId,
        public readonly FeedbackCategory $category,
        public readonly string $feedbackMessage,
        public readonly string $submittedByName,
        public readonly string $submittedByEmail,
        public readonly string $submittedByRole,
        public readonly string $submittedAt,
        public readonly string $userAgent,
        public readonly array $page,
        public readonly array $environment,
        public readonly array $diagnostics,
        public readonly int $recipientAdminId,
        public readonly string $recipientName,
        public readonly string $recipientEmail,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        Mail::to($this->recipientEmail, $this->recipientName)->sendNow(new UserFeedbackMail(
            feedbackId: $this->feedbackId,
            category: $this->category,
            feedbackMessage: $this->feedbackMessage,
            submittedByName: $this->submittedByName,
            submittedByEmail: $this->submittedByEmail,
            submittedByRole: $this->submittedByRole,
            submittedAt: $this->submittedAt,
            userAgent: $this->userAgent,
            page: $this->page,
            environment: $this->environment,
            diagnostics: $this->diagnostics,
            recipientAdminId: $this->recipientAdminId,
            recipientName: $this->recipientName,
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('User feedback email delivery failed', [
            'feedback_id' => $this->feedbackId,
            'recipient_admin_id' => $this->recipientAdminId,
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }
}
