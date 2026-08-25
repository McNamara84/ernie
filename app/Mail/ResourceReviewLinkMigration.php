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
 * Review-link migration notification for one resource and one ContactPerson contributor.
 */
final class ResourceReviewLinkMigration extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $resourceId,
        public readonly string $resourceTitle,
        public readonly ?string $resourceDoi,
        public readonly string $reviewUrl,
        public readonly string $recipientName,
        public readonly string $contactAddress,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your review link has changed - {$this->resourceTitle}",
            replyTo: [$this->contactAddress],
            cc: [$this->contactAddress],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.resource-review-link-migration',
            text: 'emails.resource-review-link-migration-text',
            with: [
                'resourceTitle' => $this->resourceTitle,
                'resourceDoi' => $this->resourceDoi,
                'reviewUrl' => $this->reviewUrl,
                'recipientName' => $this->recipientName,
                'contactAddress' => $this->contactAddress,
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
        Log::error('Resource review-link migration email delivery failed', [
            'resource_id' => $this->resourceId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
