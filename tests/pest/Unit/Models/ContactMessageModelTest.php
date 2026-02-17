<?php

declare(strict_types=1);

use App\Models\ContactMessage;
use App\Models\Resource;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('isSent', function () {
    test('returns true when sent_at is set', function () {
        $message = ContactMessage::factory()->sent()->create();

        expect($message->isSent())->toBeTrue();
    });

    test('returns false when sent_at is null', function () {
        $message = ContactMessage::factory()->pending()->create();

        expect($message->isSent())->toBeFalse();
    });
});

describe('markAsSent', function () {
    test('sets sent_at timestamp', function () {
        $message = ContactMessage::factory()->pending()->create();

        expect($message->isSent())->toBeFalse();

        $message->markAsSent();

        expect($message->fresh()->isSent())->toBeTrue()
            ->and($message->fresh()->sent_at)->not->toBeNull();
    });
});

describe('countRecentFromIp', function () {
    test('counts messages from same IP within timeframe', function () {
        $ip = '192.168.1.100';

        ContactMessage::factory()->count(3)->create([
            'ip_address' => $ip,
            'created_at' => now()->subMinutes(30),
        ]);

        // Old message outside window
        ContactMessage::factory()->create([
            'ip_address' => $ip,
            'created_at' => now()->subMinutes(120),
        ]);

        // Different IP
        ContactMessage::factory()->create([
            'ip_address' => '10.0.0.1',
        ]);

        expect(ContactMessage::countRecentFromIp($ip))->toBe(3);
    });

    test('accepts custom minutes parameter', function () {
        $ip = '192.168.1.100';

        ContactMessage::factory()->create([
            'ip_address' => $ip,
            'created_at' => now()->subMinutes(15),
        ]);

        ContactMessage::factory()->create([
            'ip_address' => $ip,
            'created_at' => now()->subMinutes(45),
        ]);

        expect(ContactMessage::countRecentFromIp($ip, 30))->toBe(1);
    });

    test('returns zero when no recent messages', function () {
        expect(ContactMessage::countRecentFromIp('1.2.3.4'))->toBe(0);
    });
});

describe('relationships', function () {
    test('belongs to resource', function () {
        $message = ContactMessage::factory()->create();

        expect($message->resource)->toBeInstanceOf(Resource::class);
    });
});

describe('attribute casting', function () {
    test('send_to_all is boolean', function () {
        $message = ContactMessage::factory()->create(['send_to_all' => 1]);

        expect($message->send_to_all)->toBeBool();
    });

    test('copy_to_sender is boolean', function () {
        $message = ContactMessage::factory()->create(['copy_to_sender' => 0]);

        expect($message->copy_to_sender)->toBeBool();
    });

    test('sent_at is datetime', function () {
        $message = ContactMessage::factory()->sent()->create();

        expect($message->sent_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    });
});
