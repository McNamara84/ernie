<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\ContactMessage;
use App\Models\Resource;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sent to Contact Person(s) when a visitor submits a contact form.
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
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        $title = $this->resource->titles->first();
        $mainTitle = $title !== null ? $title->value : 'Dataset';

        return new Envelope(
            replyTo: [
                new Address($this->contactMessage->sender_email, $this->contactMessage->sender_name),
            ],
            subject: "Message regarding dataset: {$mainTitle}",
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
            markdown: 'emails.contact-person-message',
            with: [
                'recipientName' => $this->recipientName,
                'senderName' => $this->contactMessage->sender_name,
                'senderEmail' => $this->contactMessage->sender_email,
                'messageContent' => $this->contactMessage->message,
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
