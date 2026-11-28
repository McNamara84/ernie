<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class CurationNotification extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $resourceTitle, public readonly string $resourceUrl, public readonly string $messageText, public readonly string $actorName, public readonly string $kind) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->kind === 'mention' ? 'Mention in curation: ' : 'Curation assigned: ').$this->resourceTitle);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.curation-notification');
    }
}
