<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Review invitation for one resource and one ContactPerson contributor.
 */
final class ResourceReviewLink extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $resourceId,
        public readonly string $resourceTitle,
        public readonly ?string $resourceDoi,
        public readonly string $reviewUrl,
        public readonly string $recipientName,
        public readonly string $initiatorName,
        public readonly string $initiatorEmail,
        public readonly string $contactAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Review requested: {$this->resourceTitle}",
            replyTo: [$this->contactAddress],
            cc: [$this->contactAddress],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resource-review-link',
            text: 'emails.resource-review-link-text',
            with: [
                'resourceTitle' => $this->resourceTitle,
                'resourceDoi' => $this->resourceDoi,
                'reviewUrl' => $this->reviewUrl,
                'recipientName' => $this->recipientName,
                'initiatorName' => $this->initiatorName,
                'initiatorEmail' => $this->initiatorEmail,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Resource review email delivery failed', [
            'resource_id' => $this->resourceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
