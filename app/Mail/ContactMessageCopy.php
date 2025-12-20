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

/**
 * Confirmation email sent to the sender with a copy of their message.
 */
class ContactMessageCopy extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     *
     * @param  array<string>  $recipientNames
     */
    public function __construct(
        public ContactMessage $contactMessage,
        public Resource $resource,
        public array $recipientNames,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $title = $this->resource->titles->first();
        $mainTitle = $title !== null ? $title->value : 'Dataset';

        return new Envelope(
            subject: "Your message regarding: {$mainTitle} - Confirmation",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $title = $this->resource->titles->first();
        $mainTitle = $title !== null ? $title->value : 'Dataset';
        $doi = $this->resource->doi;
        $doiUrl = $doi ? "https://doi.org/{$doi}" : null;

        return new Content(
            markdown: 'emails.contact-message-copy',
            with: [
                'senderName' => $this->contactMessage->sender_name,
                'messageContent' => $this->contactMessage->message,
                'recipientNames' => $this->recipientNames,
                'datasetTitle' => $mainTitle,
                'doi' => $doi,
                'doiUrl' => $doiUrl,
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
}
