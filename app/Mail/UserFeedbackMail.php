<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\FeedbackCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

final class UserFeedbackMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;
    use SerializesModels;

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
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function envelope(): Envelope
    {
        $shortId = substr($this->feedbackId, 0, 8);

        return new Envelope(
            subject: sprintf('[ERNIE Feedback] %s — %s', $this->category->label(), $shortId),
            replyTo: [new Address($this->submittedByEmail, $this->submittedByName)],
            using: [function (Email $message): void {
                $message->getHeaders()->addTextHeader('X-ERNIE-Feedback-ID', $this->feedbackId);
            }],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-feedback',
            text: 'emails.user-feedback-text',
            with: [
                'feedbackId' => $this->feedbackId,
                'categoryLabel' => $this->category->label(),
                'feedbackMessage' => $this->feedbackMessage,
                'submittedByName' => $this->submittedByName,
                'submittedByEmail' => $this->submittedByEmail,
                'submittedByRole' => $this->submittedByRole,
                'submittedAt' => $this->submittedAt,
                'userAgent' => $this->userAgent,
                'page' => $this->page,
                'environment' => $this->environment,
                'diagnostics' => $this->diagnostics,
                'recipientName' => $this->recipientName,
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('User feedback email delivery failed', [
            'feedback_id' => $this->feedbackId,
            'recipient_admin_id' => $this->recipientAdminId,
            'exception' => $exception !== null ? $exception::class : null,
            'error' => $exception !== null ? Str::limit($exception->getMessage(), 500, '') : null,
        ]);
    }
}
