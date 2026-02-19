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
            'send_to_all',
            'sender_name',
            'sender_email',
            'message',
            'copy_to_sender',
            'ip_address',
            'sent_at',
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

    it('casts sent_at to datetime', function () {
        $model = new ContactMessage(['sent_at' => '2025-01-15 10:30:00']);

        expect($model->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
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
