<?php

declare(strict_types=1);

use App\Models\ContactMessage;
use App\Models\Resource;

covers(ContactMessage::class);

describe('fillable', function () {
    it('has correct fillable fields', function () {
        $model = new ContactMessage;

        expect($model->getFillable())->toBe([
            'resource_id',
            'resource_creator_id',
            'resource_contributor_id',
            'send_to_all',
            'sender_name',
            'sender_email',
            'message',
            'copy_to_sender',
            'ip_address',
            'recipient_count',
            'delivered_recipient_count',
            'queued_at',
            'sent_at',
            'failed_at',
            'failure_reason',
        ]);
    });
});

describe('casts', function () {
    it('casts send_to_all to boolean', function () {
        $model = new ContactMessage(['send_to_all' => 1]);

        expect($model->send_to_all)->toBeBool();
    });

    it('casts copy_to_sender to boolean', function () {
        $model = new ContactMessage(['copy_to_sender' => 1]);

        expect($model->copy_to_sender)->toBeBool();
    });

    it('casts recipient_count to integer', function () {
        $model = new ContactMessage(['recipient_count' => '2']);

        expect($model->recipient_count)->toBeInt();
    });

    it('casts delivered_recipient_count to integer', function () {
        $model = new ContactMessage(['delivered_recipient_count' => '1']);

        expect($model->delivered_recipient_count)->toBeInt();
    });

    it('casts queued_at to datetime', function () {
        $model = new ContactMessage(['queued_at' => '2025-01-15 10:00:00']);

        expect($model->queued_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('casts sent_at to datetime', function () {
        $model = new ContactMessage(['sent_at' => '2025-01-15 10:30:00']);

        expect($model->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('casts failed_at to datetime', function () {
        $model = new ContactMessage(['failed_at' => '2025-01-15 11:00:00']);

        expect($model->failed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });
});

describe('relationships', function () {
    it('defines resource relationship', function () {
        $model = new ContactMessage;

        expect($model->resource())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });

    it('defines resourceCreator relationship', function () {
        $model = new ContactMessage;

        expect($model->resourceCreator())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
    });
});

describe('isSent', function () {
    it('returns true when sent_at is set', function () {
        $model = new ContactMessage(['sent_at' => '2025-01-15 10:30:00']);

        expect($model->isSent())->toBeTrue();
    });

    it('returns false when sent_at is null', function () {
        $model = new ContactMessage(['sent_at' => null]);

        expect($model->isSent())->toBeFalse();
    });
});

describe('markAsSent', function () {
    it('updates sent_at timestamp', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'sent_at' => null,
        ]);

        expect($contactMessage->isSent())->toBeFalse();

        $contactMessage->markAsSent();
        $contactMessage->refresh();

        expect($contactMessage->isSent())->toBeTrue()
            ->and($contactMessage->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('does not overwrite an existing sent_at timestamp', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'sent_at' => '2025-01-15 10:30:00',
        ]);

        $contactMessage->markAsSent();
        $contactMessage->refresh();

        expect($contactMessage->sent_at?->toDateTimeString())->toBe('2025-01-15 10:30:00');
    });
});

describe('markAsQueued', function () {
    it('updates queued_at timestamp', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'queued_at' => null,
        ]);

        $contactMessage->markAsQueued();
        $contactMessage->refresh();

        expect($contactMessage->queued_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('does not overwrite an existing queued_at timestamp', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'queued_at' => '2025-01-15 10:00:00',
        ]);

        $contactMessage->markAsQueued();
        $contactMessage->refresh();

        expect($contactMessage->queued_at?->toDateTimeString())->toBe('2025-01-15 10:00:00');
    });
});

describe('markAsFailed', function () {
    it('updates failed_at and failure_reason', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        $contactMessage->markAsFailed('SMTP unavailable');
        $contactMessage->refresh();

        expect($contactMessage->failed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class)
            ->and($contactMessage->failure_reason)->toBe('SMTP unavailable');
    });

    it('does not overwrite an existing failure state', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'failed_at' => '2025-01-15 11:00:00',
            'failure_reason' => 'Original failure',
        ]);

        $contactMessage->markAsFailed('Replacement failure');
        $contactMessage->refresh();

        expect($contactMessage->failed_at?->toDateTimeString())->toBe('2025-01-15 11:00:00')
            ->and($contactMessage->failure_reason)->toBe('Original failure');
    });

    it('does not mark an already sent message as failed', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'recipient_count' => 1,
            'delivered_recipient_count' => 1,
            'sent_at' => '2025-01-15 11:05:00',
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        $contactMessage->markAsFailed('SMTP unavailable');
        $contactMessage->refresh();

        expect($contactMessage->sent_at?->toDateTimeString())->toBe('2025-01-15 11:05:00')
            ->and($contactMessage->failed_at)->toBeNull()
            ->and($contactMessage->failure_reason)->toBeNull();
    });
});

describe('markRecipientDelivered', function () {
    it('increments delivered recipient count without marking sent before the final delivery', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'recipient_count' => 2,
            'delivered_recipient_count' => 0,
            'sent_at' => null,
            'failed_at' => null,
        ]);

        $contactMessage->markRecipientDelivered();
        $contactMessage->refresh();

        expect($contactMessage->delivered_recipient_count)->toBe(1)
            ->and($contactMessage->sent_at)->toBeNull();
    });

    it('marks the message as sent when the final tracked recipient is delivered', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'recipient_count' => 2,
            'delivered_recipient_count' => 1,
            'sent_at' => null,
            'failed_at' => null,
        ]);

        $contactMessage->markRecipientDelivered();
        $contactMessage->refresh();

        expect($contactMessage->delivered_recipient_count)->toBe(2)
            ->and($contactMessage->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });

    it('ignores recipient deliveries after the message has already failed', function () {
        $resource = Resource::factory()->create();
        $contactMessage = ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'recipient_count' => 2,
            'delivered_recipient_count' => 1,
            'sent_at' => null,
            'failed_at' => '2025-01-15 11:00:00',
        ]);

        $contactMessage->markRecipientDelivered();
        $contactMessage->refresh();

        expect($contactMessage->delivered_recipient_count)->toBe(1)
            ->and($contactMessage->sent_at)->toBeNull();
    });
});

describe('countRecentFromIp', function () {
    it('counts messages from same IP within time window', function () {
        $resource = Resource::factory()->create();

        ContactMessage::factory()->count(3)->create([
            'resource_id' => $resource->id,
            'ip_address' => '192.168.1.1',
        ]);

        ContactMessage::factory()->create([
            'resource_id' => $resource->id,
            'ip_address' => '192.168.1.2',
        ]);

        expect(ContactMessage::countRecentFromIp('192.168.1.1'))->toBe(3)
            ->and(ContactMessage::countRecentFromIp('192.168.1.2'))->toBe(1)
            ->and(ContactMessage::countRecentFromIp('10.0.0.1'))->toBe(0);
    });
});
