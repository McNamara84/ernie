<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Resource;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class DataEmbargoExpired extends Mailable
{
    public readonly string $resourceTitle;

    public readonly ?string $identifier;

    public readonly string $editorUrl;

    public readonly ?string $landingPageUrl;

    public function __construct(Resource $resource, public readonly string $availableDate)
    {
        $this->resourceTitle = $resource->titles->first(fn ($title): bool => $title->isMainTitle())->value ?? 'Untitled Resource';
        $this->identifier = $resource->doi;
        $this->editorUrl = url('/editor?resourceId='.$resource->id);
        $this->landingPageUrl = $resource->landingPage?->isPublished() ? $resource->landingPage->public_url : null;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Data embargoes ready for release');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.data-embargo-expired', text: 'emails.data-embargo-expired-text');
    }
}
