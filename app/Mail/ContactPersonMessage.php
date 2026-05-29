<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactMessage;
use App\Models\Resource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Contact Person Message Mail
 *
 * Sent to contact persons when someone submits the landing page contact form.
 * Uses multipart HTML/Text for maximum compatibility.
 */
class ContactPersonMessage extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public ContactMessage $contactMessage,
        public Resource $resource,
        public string $recipientName,
        public bool $isCopyToSender = false,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $firstTitle = $this->resource->titles->first();
        $datasetTitle = $firstTitle !== null ? $firstTitle->value : 'Dataset';

        $subject = $this->isCopyToSender
            ? "[Copy] Contact request for: {$datasetTitle}"
            : "Contact request for: {$datasetTitle}";

        $using = [];

        if (! $this->isCopyToSender) {
            $using[] = function (Email $message): void {
                $message->getHeaders()->addTextHeader(
                    'X-Contact-Message-Id',
                    (string) $this->contactMessage->getKey(),
                );
            };
        }

        return new Envelope(
            subject: $subject,
            replyTo: [$this->contactMessage->sender_email],
            using: $using,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $firstTitle = $this->resource->titles->first();
        $datasetTitle = $firstTitle !== null ? $firstTitle->value : 'Dataset';

        return new Content(
            view: 'emails.contact-person-message',
            text: 'emails.contact-person-message-text',
            with: [
                'senderName' => $this->contactMessage->sender_name,
                'senderEmail' => $this->contactMessage->sender_email,
                'messageContent' => $this->contactMessage->message,
                'datasetTitle' => $datasetTitle,
                'datasetDoi' => $this->resource->doi,
                'datasetUrl' => $this->resource->landingPage !== null
                    ? $this->resource->landingPage->public_url
                    : url('/'),
                'recipientName' => $this->recipientName,
                'isCopyToSender' => $this->isCopyToSender,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->isCopyToSender) {
            return;
        }

        $contactMessageId = $this->contactMessage->getKey();

        if (! is_int($contactMessageId) && ! is_string($contactMessageId)) {
            return;
        }

        $contactMessage = ContactMessage::query()->find((int) $contactMessageId);

        if ($contactMessage === null) {
            return;
        }

        $contactMessage->markAsFailed($exception?->getMessage());
    }
}
