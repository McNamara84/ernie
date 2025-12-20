<?php

declare(strict_types=1);

use App\Models\ContactMessage;
use App\Models\ContributorType;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Services\ContactMessageService;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

describe('ContactMessageService', function () {
    it('creates a contact message record', function () {
        // Arrange
        $resource = Resource::factory()->create();
        $contactPersonType = ContributorType::firstOrCreate(
            ['name' => 'ContactPerson'],
            ['slug' => 'ContactPerson']
        );
        $person = Person::factory()->create([
            'given_name' => 'John',
            'family_name' => 'Doe',
        ]);
        $contributor = ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contactPersonType->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'email' => 'john.doe@example.com',
            'position' => 1,
        ]);

        $service = new ContactMessageService();

        // Act
        $message = $service->processContactForm(
            resource: $resource,
            senderName: 'Jane Smith',
            senderEmail: 'jane@example.com',
            recipientContributorIds: [$contributor->id],
            message: 'This is a test message that is long enough.',
            sendCopyToSender: false,
            ipAddress: '127.0.0.1',
            honeypotTriggered: false,
        );

        // Assert
        expect($message)->toBeInstanceOf(ContactMessage::class)
            ->and($message->sender_name)->toBe('Jane Smith')
            ->and($message->sender_email)->toBe('jane@example.com')
            ->and($message->message)->toBe('This is a test message that is long enough.')
            ->and($message->ip_address)->toBe('127.0.0.1')
            ->and($message->honeypot_triggered)->toBeFalse()
            ->and($message->sent_at)->not->toBeNull();
    });

    it('does not send emails when honeypot is triggered', function () {
        // Arrange
        $resource = Resource::factory()->create();
        $contactPersonType = ContributorType::firstOrCreate(
            ['name' => 'ContactPerson'],
            ['slug' => 'ContactPerson']
        );
        $person = Person::factory()->create();
        $contributor = ResourceContributor::create([
            'resource_id' => $resource->id,
            'contributor_type_id' => $contactPersonType->id,
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
            'email' => 'contact@example.com',
            'position' => 1,
        ]);

        $service = new ContactMessageService();

        // Act
        $message = $service->processContactForm(
            resource: $resource,
            senderName: 'Spam Bot',
            senderEmail: 'bot@spam.com',
            recipientContributorIds: [$contributor->id],
            message: 'Buy cheap products now!',
            sendCopyToSender: false,
            ipAddress: '192.168.1.1',
            honeypotTriggered: true,
        );

        // Assert
        expect($message->honeypot_triggered)->toBeTrue()
            ->and($message->sent_at)->toBeNull();
        
        Mail::assertNothingSent();
    });

    it('rate limits by IP address', function () {
        // Arrange
        $ipAddress = '10.0.0.1';
        $resource = Resource::factory()->create();

        // Create 5 messages from the same IP
        for ($i = 0; $i < 5; $i++) {
            ContactMessage::create([
                'resource_id' => $resource->id,
                'sender_name' => "Sender {$i}",
                'sender_email' => "sender{$i}@example.com",
                'recipient_contributor_ids' => [1],
                'message' => 'Test message',
                'ip_address' => $ipAddress,
                'honeypot_triggered' => false,
                'send_copy_to_sender' => false,
            ]);
        }

        $service = new ContactMessageService();

        // Act & Assert
        expect($service->isRateLimited($ipAddress))->toBeTrue()
            ->and($service->getRemainingMessages($ipAddress))->toBe(0);
    });

    it('allows messages when under rate limit', function () {
        // Arrange
        $service = new ContactMessageService();

        // Act & Assert
        expect($service->isRateLimited('192.168.1.100'))->toBeFalse()
            ->and($service->getRemainingMessages('192.168.1.100'))->toBe(5);
    });
});
