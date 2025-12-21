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

        return new Envelope(
            subject: $subject,
            replyTo: [$this->contactMessage->sender_email],
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
                'datasetUrl' => route('landing-page.show', $this->resource->id),
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
}
