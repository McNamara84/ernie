<?php

declare(strict_types=1);

use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Title;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

describe('ContactMessageController', function (): void {

    beforeEach(function (): void {
        // Disable throttling for all tests in this file
        $this->withoutMiddleware(ThrottleRequests::class);
    });

    describe('store (published landing page)', function (): void {

        it('sends contact message successfully', function (): void {
            Mail::fake();

            // Create resource with a creator who has email
            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $person = Person::factory()->create([
                'given_name' => 'John',
                'family_name' => 'Doe',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'john.doe@example.com',
            ]);

            // Create landing page with proper DOI prefix format
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.test.001',
                'slug' => 'test-dataset',
            ]);

            $response = $this->postJson('/10.5880/gfz.test.001/test-dataset/contact', [
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
                'message' => 'This is a test message for the contact form.',
                'send_to_all' => true,
            ]);

            $response->assertOk()
                ->assertJson(['message' => 'Message sent successfully.']);

            Mail::assertQueued(ContactPersonMessage::class);

            $this->assertDatabaseHas('contact_messages', [
                'resource_id' => $resource->id,
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
            ]);
        });

        it('returns 404 for non-existent landing page', function (): void {
            $response = $this->postJson('/10.5880/gfz.nonexistent.999/non-existent/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Test message that is long enough.',
            ]);

            $response->assertNotFound();
        });

        it('triggers honeypot but returns success', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.honeypot.001',
                'slug' => 'honeypot-test',
            ]);

            // Send with honeypot field filled (bot detection)
            $response = $this->postJson('/10.5880/gfz.honeypot.001/honeypot-test/contact', [
                'sender_name' => 'Bot User',
                'sender_email' => 'bot@spam.com',
                'message' => 'Spam message content here.',
                'website_url' => 'http://spam-site.com', // Honeypot field
            ]);

            // Should return success to not reveal detection
            $response->assertOk()
                ->assertJson(['message' => 'Message sent successfully.']);

            // But no email should be sent
            Mail::assertNothingQueued();

            // No message saved
            $this->assertDatabaseMissing('contact_messages', [
                'sender_email' => 'bot@spam.com',
            ]);
        });

        it('validates required fields', function (): void {
            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.validation.001',
                'slug' => 'validation-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.validation.001/validation-test/contact', [
                // Missing all required fields
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['sender_name', 'sender_email', 'message']);
        });

        it('validates message minimum length', function (): void {
            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.minlen.001',
                'slug' => 'min-length-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.minlen.001/min-length-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Short', // Less than 10 characters
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['message']);
        });

        it('validates email format', function (): void {
            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.email.001',
                'slug' => 'email-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.email.001/email-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'not-an-email',
                'message' => 'This is a valid message content.',
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['sender_email']);
        });

        it('fails when no contact persons available', function (): void {
            // Resource without any creators with email
            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.nocontact.001',
                'slug' => 'no-contacts',
            ]);

            $response = $this->postJson('/10.5880/gfz.nocontact.001/no-contacts/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'This is a test message content.',
                'send_to_all' => true,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['recipients']);
        });

        it('sends copy to sender when requested', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'creator@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.copy.001',
                'slug' => 'copy-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.copy.001/copy-test/contact', [
                'sender_name' => 'Jane Doe',
                'sender_email' => 'jane@example.com',
                'message' => 'Please send me a copy of this message.',
                'send_to_all' => true,
                'copy_to_sender' => true,
            ]);

            $response->assertOk();

            // Should queue 2 emails: one to creator, one copy to sender
            Mail::assertQueued(ContactPersonMessage::class, 2);

            $this->assertDatabaseHas('contact_messages', [
                'copy_to_sender' => true,
            ]);
        });

        it('sends to specific creator when resource_creator_id provided', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();

            $person1 = Person::factory()->create(['given_name' => 'First', 'family_name' => 'Creator']);
            $creator1 = ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person1->id,
                'email' => 'first@example.com',
            ]);

            $person2 = Person::factory()->create(['given_name' => 'Second', 'family_name' => 'Creator']);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person2->id,
                'email' => 'second@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.specific.001',
                'slug' => 'specific-creator',
            ]);

            $response = $this->postJson('/10.5880/gfz.specific.001/specific-creator/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message for specific creator only.',
                'send_to_all' => false,
                'resource_creator_id' => $creator1->id,
            ]);

            $response->assertOk()
                ->assertJson(['recipients_count' => 1]);

            // Only one email sent
            Mail::assertQueued(ContactPersonMessage::class, 1);
        });

        it('handles institutional creators', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            $institution = Institution::factory()->create(['name' => 'GFZ Potsdam']);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Institution::class,
                'creatorable_id' => $institution->id,
                'email' => 'info@gfz-potsdam.de',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.inst.001',
                'slug' => 'institution-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.inst.001/institution-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message to institutional contact.',
                'send_to_all' => true,
            ]);

            $response->assertOk();
            Mail::assertQueued(ContactPersonMessage::class);
        });

    });

    describe('storeDraft (draft landing page without DOI)', function (): void {

        it('sends contact message for draft landing page', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'creator@example.com',
            ]);

            // Create draft landing page (no DOI prefix)
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => null,
                'slug' => 'draft-dataset',
            ]);

            $response = $this->postJson("/draft-{$resource->id}/draft-dataset/contact", [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message for draft landing page.',
                'send_to_all' => true,
            ]);

            $response->assertOk()
                ->assertJson(['message' => 'Message sent successfully.']);

            Mail::assertQueued(ContactPersonMessage::class);
        });

        it('returns 404 for non-existent draft landing page', function (): void {
            $response = $this->postJson('/draft-99999/non-existent/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Test message content here.',
            ]);

            $response->assertNotFound();
        });

        it('returns 404 when slug does not match', function (): void {
            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => null,
                'slug' => 'correct-slug',
            ]);

            $response = $this->postJson("/draft-{$resource->id}/wrong-slug/contact", [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Test message content here.',
            ]);

            $response->assertNotFound();
        });

    });

    describe('rate limiting', function (): void {

        it('enforces rate limit after 5 messages', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'creator@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.ratelimit.001',
                'slug' => 'rate-limit-test',
            ]);

            // Create 5 existing messages from same IP
            for ($i = 0; $i < 5; $i++) {
                ContactMessage::factory()->create([
                    'resource_id' => $resource->id,
                    'ip_address' => '127.0.0.1',
                    'created_at' => now()->subMinutes(5),
                ]);
            }

            // 6th message should be rate limited
            $response = $this->postJson('/10.5880/gfz.ratelimit.001/rate-limit-test/contact', [
                'sender_name' => 'Rate Limited User',
                'sender_email' => 'limited@example.com',
                'message' => 'This message should be rate limited.',
                'send_to_all' => true,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['rate_limit']);
        });

    });

    describe('cc email functionality (Issue #456)', function (): void {

        it('adds cc to first recipient when configured', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $person = Person::factory()->create([
                'given_name' => 'John',
                'family_name' => 'Doe',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'john.doe@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.cc.001',
                'slug' => 'cc-test',
            ]);

            $this->postJson('/10.5880/gfz.cc.001/cc-test/contact', [
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
                'message' => 'This is a test message for cc functionality.',
                'send_to_all' => true,
            ])->assertOk();

            Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
                return $mail->hasCc('datapub@gfz.de');
            });
        });

        it('does not add cc when config is empty', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => '']);

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $person = Person::factory()->create([
                'given_name' => 'Jane',
                'family_name' => 'Doe',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'jane.doe@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.nocc.001',
                'slug' => 'no-cc-test',
            ]);

            $this->postJson('/10.5880/gfz.nocc.001/no-cc-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'This is a test message without cc.',
                'send_to_all' => true,
            ])->assertOk();

            Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
                // Verify no Cc is added to any email when config is empty
                return empty($mail->cc);
            });
        });

        it('does not add cc to copy-to-sender emails', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $person = Person::factory()->create([
                'given_name' => 'John',
                'family_name' => 'Doe',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'john.doe@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.sender.001',
                'slug' => 'sender-copy-test',
            ]);

            $this->postJson('/10.5880/gfz.sender.001/sender-copy-test/contact', [
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
                'message' => 'This is a test message with copy to sender.',
                'send_to_all' => true,
                'copy_to_sender' => true,
            ])->assertOk();

            // Verify the copy to sender email does NOT have Cc
            // AND the contact person email DOES have Cc
            $senderCopyHasNoCc = false;
            $contactPersonHasCc = false;

            Mail::assertQueued(ContactPersonMessage::class, function ($mail) use (&$senderCopyHasNoCc, &$contactPersonHasCc) {
                if ($mail->isCopyToSender) {
                    // Sender copy should NOT have Cc
                    $senderCopyHasNoCc = empty($mail->cc);
                } else {
                    // Contact person email should HAVE Cc
                    $contactPersonHasCc = $mail->hasCc('datapub@gfz.de');
                }

                return true;
            });

            expect($senderCopyHasNoCc)->toBeTrue('Sender copy should not have Cc');
            expect($contactPersonHasCc)->toBeTrue('Contact person email should have Cc');
        });

        it('adds cc only to first recipient when sending to multiple contact persons', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            // Create multiple contact persons
            $person1 = Person::factory()->create(['given_name' => 'Alice', 'family_name' => 'First']);
            $person2 = Person::factory()->create(['given_name' => 'Bob', 'family_name' => 'Second']);
            $person3 = Person::factory()->create(['given_name' => 'Carol', 'family_name' => 'Third']);

            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person1->id,
                'email' => 'alice@example.com',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person2->id,
                'email' => 'bob@example.com',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person3->id,
                'email' => 'carol@example.com',
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.multi.001',
                'slug' => 'multi-recipient-test',
            ]);

            $this->postJson('/10.5880/gfz.multi.001/multi-recipient-test/contact', [
                'sender_name' => 'Multi Sender',
                'sender_email' => 'multi@example.com',
                'message' => 'This is a test message for multiple recipients.',
                'send_to_all' => true,
            ])->assertOk();

            // Verify exactly 3 emails were queued (one per recipient)
            Mail::assertQueued(ContactPersonMessage::class, 3);

            // Count how many emails have Cc
            $emailsWithCc = 0;
            Mail::assertQueued(ContactPersonMessage::class, function ($mail) use (&$emailsWithCc) {
                if ($mail->hasCc('datapub@gfz.de')) {
                    $emailsWithCc++;
                }

                return true;
            });

            // Only 1 email should have Cc
            expect($emailsWithCc)->toBe(1);
        });

    });

});
