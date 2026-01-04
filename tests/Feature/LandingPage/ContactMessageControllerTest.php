<?php

declare(strict_types=1);

use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use Illuminate\Support\Facades\Mail;

uses()->group('landing-pages', 'contact');

beforeEach(function () {
    Mail::fake();

    $this->resource = Resource::factory()->create([
        'doi' => '10.5880/test.contact.001',
    ]);
    $this->landingPage = LandingPage::factory()->published()->create([
        'resource_id' => $this->resource->id,
        'doi_prefix' => '10.5880/test.contact.001',
        'slug' => 'test-dataset-contact',
    ]);

    // Create a contact person (creator with email)
    $this->person = Person::factory()->create([
        'given_name' => 'John',
        'family_name' => 'Doe',
        'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
        'name_identifier_scheme' => 'ORCID',
    ]);

    $this->creator = ResourceCreator::factory()->create([
        'resource_id' => $this->resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $this->person->id,
        'email' => 'john.doe@example.com',
        'website' => 'https://example.com/johndoe',
        'position' => 1,
    ]);

    // Contact URL for the landing page
    $this->contactUrl = "/{$this->landingPage->doi_prefix}/{$this->landingPage->slug}/contact";
});

describe('Contact Form Validation', function () {
    test('requires sender name', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_email' => 'sender@example.com',
            'message' => 'Test message content here',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sender_name']);
    });

    test('requires sender email', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'message' => 'Test message content here',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sender_email']);
    });

    test('requires valid email format', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'invalid-email',
            'message' => 'Test message content here',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sender_email']);
    });

    test('requires message with minimum length', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'Short',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['message']);
    });

    test('accepts valid contact form submission', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a valid test message with sufficient length.',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Message sent successfully.',
                'recipients_count' => 1,
            ]);
    });
});

describe('Honeypot Protection', function () {
    test('silently ignores submissions with honeypot field filled', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Bot Sender',
            'sender_email' => 'bot@example.com',
            'message' => 'This is a bot message with honeypot filled.',
            'resource_creator_id' => $this->creator->id,
            'website_url' => 'http://spam-site.com', // Honeypot field
        ]);

        // Should return success but not actually send email
        $response->assertStatus(200)
            ->assertJson(['message' => 'Message sent successfully.']);

        // No email should be sent
        Mail::assertNothingSent();

        // No record should be created
        expect(ContactMessage::count())->toBe(0);
    });

    test('processes submissions with empty honeypot field', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Real Sender',
            'sender_email' => 'real@example.com',
            'message' => 'This is a real message from a human user.',
            'resource_creator_id' => $this->creator->id,
            'website_url' => '', // Empty honeypot
        ]);

        $response->assertStatus(200);

        // Email should be queued
        Mail::assertQueued(ContactPersonMessage::class);

        // Record should be created
        expect(ContactMessage::count())->toBe(1);
    });
});

describe('Email Sending', function () {
    test('sends email to single contact person', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message for a single recipient.',
            'resource_creator_id' => $this->creator->id,
            'send_to_all' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['recipients_count' => 1]);

        Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
            return $mail->hasTo('john.doe@example.com')
                && $mail->recipientName === 'John Doe'
                && $mail->isCopyToSender === false;
        });
    });

    test('sends email to all contact persons', function () {
        // Add second contact person
        $person2 = Person::factory()->create([
            'given_name' => 'Jane',
            'family_name' => 'Smith',
        ]);

        ResourceCreator::factory()->create([
            'resource_id' => $this->resource->id,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person2->id,
            'email' => 'jane.smith@example.com',
            'position' => 2,
        ]);

        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message for all recipients.',
            'send_to_all' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson(['recipients_count' => 2]);

        Mail::assertQueued(ContactPersonMessage::class, 2);
    });

    test('sends copy to sender when requested', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message with copy to sender.',
            'resource_creator_id' => $this->creator->id,
            'copy_to_sender' => true,
        ]);

        $response->assertStatus(200);

        // Should queue 2 emails: one to contact person, one to sender
        Mail::assertQueued(ContactPersonMessage::class, 2);

        // Verify copy to sender
        Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
            return $mail->hasTo('sender@example.com')
                && $mail->isCopyToSender === true;
        });
    });

    test('does not send copy to sender when not requested', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message without copy to sender.',
            'resource_creator_id' => $this->creator->id,
            'copy_to_sender' => false,
        ]);

        $response->assertStatus(200);

        // Should only queue 1 email
        Mail::assertQueued(ContactPersonMessage::class, 1);
    });
});

describe('Rate Limiting', function () {
    test('allows up to 5 messages per hour from same IP', function () {
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson($this->contactUrl, [
                'sender_name' => "Sender {$i}",
                'sender_email' => "sender{$i}@example.com",
                'message' => "This is test message number {$i} from the same IP.",
                'resource_creator_id' => $this->creator->id,
            ]);

            $response->assertStatus(200);
        }

        expect(ContactMessage::count())->toBe(5);
    });

    test('blocks 6th message from same IP within an hour', function () {
        // Create 5 messages from same IP
        for ($i = 1; $i <= 5; $i++) {
            ContactMessage::create([
                'resource_id' => $this->resource->id,
                'resource_creator_id' => $this->creator->id,
                'sender_name' => "Sender {$i}",
                'sender_email' => "sender{$i}@example.com",
                'message' => "Existing message {$i}",
                'ip_address' => '127.0.0.1',
                'sent_at' => now(),
            ]);
        }

        // Try to send 6th message
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Sender 6',
            'sender_email' => 'sender6@example.com',
            'message' => 'This is the 6th message and should be blocked.',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rate_limit']);
    });
});

describe('Contact Message Logging', function () {
    test('creates contact message record on successful submission', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message that should be logged.',
            'resource_creator_id' => $this->creator->id,
            'copy_to_sender' => true,
        ]);

        $response->assertStatus(200);

        $contactMessage = ContactMessage::first();
        expect($contactMessage)->not->toBeNull()
            ->and($contactMessage->resource_id)->toBe($this->resource->id)
            ->and($contactMessage->resource_creator_id)->toBe($this->creator->id)
            ->and($contactMessage->sender_name)->toBe('Test Sender')
            ->and($contactMessage->sender_email)->toBe('sender@example.com')
            ->and($contactMessage->message)->toBe('This is a test message that should be logged.')
            ->and($contactMessage->copy_to_sender)->toBeTrue()
            ->and($contactMessage->sent_at)->not->toBeNull();
    });

    test('logs IP address', function () {
        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This is a test message with IP logging.',
            'resource_creator_id' => $this->creator->id,
        ]);

        $response->assertStatus(200);

        $contactMessage = ContactMessage::first();
        expect($contactMessage->ip_address)->not->toBeNull();
    });
});

describe('Edge Cases', function () {
    test('returns error when no contact persons available', function () {
        // Remove all contact persons
        ResourceCreator::where('resource_id', $this->resource->id)->delete();

        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This message has no recipients.',
            'send_to_all' => true,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['recipients']);
    });

    test('returns 404 for non-existent resource', function () {
        $response = $this->postJson('/10.5880/nonexistent/nonexistent-slug/contact', [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This message is for a non-existent resource.',
        ]);

        $response->assertStatus(404);
    });

    test('handles institution as contact person', function () {
        $institution = Institution::factory()->create([
            'name' => 'Research Institute',
        ]);

        $institutionCreator = ResourceCreator::factory()->create([
            'resource_id' => $this->resource->id,
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
            'email' => 'contact@institute.org',
            'position' => 3,
        ]);

        $response = $this->postJson($this->contactUrl, [
            'sender_name' => 'Test Sender',
            'sender_email' => 'sender@example.com',
            'message' => 'This message is for an institution contact.',
            'resource_creator_id' => $institutionCreator->id,
        ]);

        $response->assertStatus(200);

        Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
            return $mail->hasTo('contact@institute.org')
                && $mail->recipientName === 'Research Institute';
        });
    });
});
