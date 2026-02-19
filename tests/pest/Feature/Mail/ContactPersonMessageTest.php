<?php

declare(strict_types=1);

use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\Title;

covers(ContactPersonMessage::class);

beforeEach(function () {
    $this->resource = Resource::factory()->create();
    Title::factory()->create([
        'resource_id' => $this->resource->id,
        'value' => 'Seismic Activity Dataset 2025',
    ]);
    LandingPage::factory()->published()->create([
        'resource_id' => $this->resource->id,
        'doi_prefix' => '10.5880/gfz.2025.001',
        'slug' => 'seismic-activity-dataset',
    ]);
    $this->resource->load('titles', 'landingPage');

    $this->contactMessage = ContactMessage::factory()->create([
        'resource_id' => $this->resource->id,
        'sender_name' => 'Jane Doe',
        'sender_email' => 'jane@example.com',
        'message' => 'I have a question about your dataset.',
    ]);
});

describe('envelope', function () {
    it('sets subject with dataset title for recipient', function () {
        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $this->resource,
            recipientName: 'Dr. Smith',
            isCopyToSender: false,
        );

        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('Contact request for: Seismic Activity Dataset 2025')
            ->and($envelope->replyTo)->toHaveCount(1);
    });

    it('prefixes subject with [Copy] when isCopyToSender is true', function () {
        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $this->resource,
            recipientName: 'Jane Doe',
            isCopyToSender: true,
        );

        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('[Copy] Contact request for: Seismic Activity Dataset 2025');
    });

    it('falls back to "Dataset" when resource has no titles', function () {
        $resource = Resource::factory()->create();
        $resource->load('titles');

        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $resource,
            recipientName: 'Dr. Smith',
        );

        $envelope = $mailable->envelope();

        expect($envelope->subject)->toBe('Contact request for: Dataset');
    });
});

describe('content', function () {
    it('returns content with correct views', function () {
        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $this->resource,
            recipientName: 'Dr. Smith',
        );

        $content = $mailable->content();

        expect($content->view)->toBe('emails.contact-person-message')
            ->and($content->text)->toBe('emails.contact-person-message-text');
    });

    it('passes correct data to the view', function () {
        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $this->resource,
            recipientName: 'Dr. Smith',
            isCopyToSender: true,
        );

        $content = $mailable->content();

        expect($content->with)->toMatchArray([
            'senderName' => 'Jane Doe',
            'senderEmail' => 'jane@example.com',
            'messageContent' => 'I have a question about your dataset.',
            'datasetTitle' => 'Seismic Activity Dataset 2025',
            'recipientName' => 'Dr. Smith',
            'isCopyToSender' => true,
        ]);
    });
});

describe('attachments', function () {
    it('returns empty array', function () {
        $mailable = new ContactPersonMessage(
            contactMessage: $this->contactMessage,
            resource: $this->resource,
            recipientName: 'Dr. Smith',
        );

        expect($mailable->attachments())->toBeEmpty();
    });
});
