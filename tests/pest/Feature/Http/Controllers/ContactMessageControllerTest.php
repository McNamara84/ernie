<?php

declare(strict_types=1);

use App\Mail\ContactPersonMessage;
use App\Models\ContactMessage;
use App\Models\ContributorType;
use App\Models\IgsnMetadata;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\Title;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

function successfulPendingMail(?Closure $assertion = null): object
{
    return new class($assertion)
    {
        public function __construct(private readonly ?Closure $assertion) {}

        public function cc(string $address): static
        {
            return $this;
        }

        public function queue(ContactPersonMessage $mailable): void
        {
            $this->assertion?->__invoke($mailable);
        }
    };
}

function failingPendingMail(string $message): object
{
    return new class($message)
    {
        public function __construct(private readonly string $message) {}

        public function cc(string $address): static
        {
            return $this;
        }

        public function queue(ContactPersonMessage $mailable): void
        {
            throw new RuntimeException($this->message);
        }
    };
}

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
                'is_contact' => true,
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
                ->assertJson([
                    'message' => 'Message received successfully.',
                    'data_publication_team_direct_recipient' => false,
                ]);

            Mail::assertQueued(ContactPersonMessage::class);

            $this->assertDatabaseHas('contact_messages', [
                'resource_id' => $resource->id,
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
            ]);

            $contactMessage = ContactMessage::query()->latest('id')->firstOrFail();

            expect($contactMessage->queued_at)->toBeInstanceOf(Carbon::class)
                ->and($contactMessage->recipient_count)->toBe(1)
                ->and($contactMessage->delivered_recipient_count)->toBe(0)
                ->and($contactMessage->sent_at)->toBeNull()
                ->and($contactMessage->failed_at)->toBeNull()
                ->and($contactMessage->failure_reason)->toBeNull();
        });

        it('marks the contact message as queued before dispatching the first recipient mail', function (): void {
            config(['mail.landing_page_contact_cc' => '']);

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
                'is_contact' => true,
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.queue-order.001',
                'slug' => 'queue-order-test',
            ]);

            Mail::shouldReceive('to')
                ->once()
                ->with('john.doe@example.com')
                ->andReturn(successfulPendingMail(function (ContactPersonMessage $mailable): void {
                    expect($mailable->contactMessage->fresh()?->queued_at)->toBeInstanceOf(Carbon::class)
                        ->and($mailable->contactMessage->fresh()?->failed_at)->toBeNull();
                }));

            $this->postJson('/10.5880/gfz.queue-order.001/queue-order-test/contact', [
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
                'message' => 'This is a test message for the contact form.',
                'send_to_all' => true,
            ])->assertOk();
        });

        it('marks the contact message as failed when recipient dispatch throws before queuing completes', function (): void {
            config(['mail.landing_page_contact_cc' => '']);

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
                'is_contact' => true,
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.queue-fail.001',
                'slug' => 'queue-fail-test',
            ]);

            Mail::shouldReceive('to')
                ->once()
                ->with('john.doe@example.com')
                ->andReturn(failingPendingMail('Queue unavailable'));

            $this->withoutExceptionHandling();

            expect(fn () => $this->postJson('/10.5880/gfz.queue-fail.001/queue-fail-test/contact', [
                'sender_name' => 'Jane Smith',
                'sender_email' => 'jane@example.com',
                'message' => 'This is a test message for the contact form.',
                'send_to_all' => true,
            ]))->toThrow(RuntimeException::class, 'Queue unavailable');

            $contactMessage = ContactMessage::query()->latest('id')->firstOrFail();

            expect($contactMessage->queued_at)->toBeInstanceOf(Carbon::class)
                ->and($contactMessage->failed_at)->toBeInstanceOf(Carbon::class)
                ->and($contactMessage->failure_reason)->toBe('Queue unavailable');
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
                ->assertJson(['message' => 'Message received successfully.']);

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

        it('sends directly to the data publication team when no contact persons are available', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

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

            $response->assertOk()
                ->assertJson([
                    'recipients_count' => 1,
                    'data_publication_team_direct_recipient' => true,
                ]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, function (ContactPersonMessage $mail): bool {
                return $mail->hasTo('datapub@gfz.de')
                    && ! $mail->hasCc('datapub@gfz.de')
                    && $mail->recipientName === 'GFZ Data Publication Team';
            });

            $this->assertDatabaseHas('contact_messages', [
                'resource_id' => $resource->id,
                'send_to_all' => true,
                'recipient_count' => 1,
            ]);
        });

        it('fails without creating a message when no contact or team recipient is available', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => '']);

            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.no-recipient.001',
                'slug' => 'no-recipient',
            ]);

            $response = $this->postJson('/10.5880/gfz.no-recipient.001/no-recipient/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'no-recipient@example.com',
                'message' => 'This request has nowhere to be delivered.',
                'send_to_all' => true,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['recipients']);

            Mail::assertNothingQueued();
            $this->assertDatabaseMissing('contact_messages', [
                'sender_email' => 'no-recipient@example.com',
            ]);
        });

        it('fails without creating a message when the only configured recipient is invalid', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'not-an-email']);

            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.invalid-only-recipient.001',
                'slug' => 'invalid-only-recipient',
            ]);

            $response = $this->postJson('/10.5880/gfz.invalid-only-recipient.001/invalid-only-recipient/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'invalid-only-recipient@example.com',
                'message' => 'This request has an invalid configured recipient.',
                'send_to_all' => true,
            ]);

            $response->assertUnprocessable()
                ->assertJsonValidationErrors(['recipients']);

            Mail::assertNothingQueued();
            $this->assertDatabaseMissing('contact_messages', [
                'sender_email' => 'invalid-only-recipient@example.com',
            ]);
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
                'is_contact' => true,
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

            $contactMessage = ContactMessage::query()->latest('id')->firstOrFail();

            expect($contactMessage->copy_to_sender)->toBeTrue()
                ->and($contactMessage->recipient_count)->toBe(1)
                ->and($contactMessage->delivered_recipient_count)->toBe(0);
        });

        it('keeps the contact message successful when only sender-copy dispatch fails', function (): void {
            config(['mail.landing_page_contact_cc' => '']);

            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'creator@example.com',
                'is_contact' => true,
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.copy-dispatch.001',
                'slug' => 'copy-dispatch-test',
            ]);

            Mail::shouldReceive('to')
                ->once()
                ->with('creator@example.com')
                ->andReturn(successfulPendingMail());

            Mail::shouldReceive('to')
                ->once()
                ->with('jane@example.com')
                ->andReturn(failingPendingMail('Copy queue unavailable'));

            $this->postJson('/10.5880/gfz.copy-dispatch.001/copy-dispatch-test/contact', [
                'sender_name' => 'Jane Doe',
                'sender_email' => 'jane@example.com',
                'message' => 'Please send me a copy of this message.',
                'send_to_all' => true,
                'copy_to_sender' => true,
            ])->assertOk();

            $contactMessage = ContactMessage::query()->latest('id')->firstOrFail();

            expect($contactMessage->queued_at)->toBeInstanceOf(Carbon::class)
                ->and($contactMessage->failed_at)->toBeNull()
                ->and($contactMessage->failure_reason)->toBeNull();
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
                'is_contact' => true,
            ]);

            $person2 = Person::factory()->create(['given_name' => 'Second', 'family_name' => 'Creator']);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person2->id,
                'email' => 'second@example.com',
                'is_contact' => true,
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

        it('addresses a creator using the resource-specific snapshot', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            $person = Person::factory()->create([
                'given_name' => 'Philipp',
                'family_name' => 'Sommer',
            ]);
            $creator = ResourceCreator::factory()->forPerson($person)->create([
                'resource_id' => $resource->id,
                'email' => 'philipp@example.com',
                'is_contact' => true,
                'name_snapshot' => 'Sommer, Philipp S.',
                'given_name_snapshot' => 'Philipp S.',
                'family_name_snapshot' => 'Sommer',
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.snapshot.001',
                'slug' => 'snapshot-creator',
            ]);

            $this->postJson('/10.5880/gfz.snapshot.001/snapshot-creator/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message for the snapshot creator.',
                'send_to_all' => false,
                'resource_creator_id' => $creator->id,
            ])->assertOk();

            Mail::assertQueued(
                ContactPersonMessage::class,
                fn (ContactPersonMessage $mail): bool => $mail->recipientName === 'Sommer, Philipp S.',
            );
        });

        it('routes a protected current repository request using only server-side metadata', function (): void {
            Mail::fake();
            $resource = Resource::factory()->create();
            IgsnMetadata::query()->create([
                'resource_id' => $resource->id,
                'current_archive' => 'BGR Berlin',
                'current_archive_contact' => 'Tina Kollaske <TINA.KOLLASKE@BGR.DE>; tina.kollaske@bgr.de',
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.60510/gfbno7002exz3001',
                'slug' => 'protected-repository-contact',
            ]);

            $this->postJson('/10.60510/gfbno7002exz3001/protected-repository-contact/contact', [
                'sender_name' => 'Sample User',
                'sender_email' => 'sender@example.org',
                'message' => 'Please provide information about this sample.',
                'send_to_all' => false,
                'repository_contact_type' => 'current',
                'recipient_email' => 'attacker@example.org',
            ])->assertOk()->assertJson(['recipients_count' => 1]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, fn ($mail): bool => $mail->hasTo('tina.kollaske@bgr.de'));
            Mail::assertNotQueued(ContactPersonMessage::class, fn ($mail): bool => $mail->hasTo('attacker@example.org'));
            $this->assertDatabaseHas('contact_messages', [
                'resource_id' => $resource->id,
                'repository_contact_type' => 'current',
                'resource_creator_id' => null,
                'resource_contributor_id' => null,
                'send_to_all' => false,
            ]);
        });

        it('routes original and current repository contacts independently', function (): void {
            Mail::fake();
            $resource = Resource::factory()->create();
            IgsnMetadata::query()->create([
                'resource_id' => $resource->id,
                'current_archive_contact' => 'current@example.org',
                'original_archive_contact' => 'original@example.org',
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.60510/separate-repositories',
                'slug' => 'separate-repository-contacts',
            ]);

            $this->postJson('/10.60510/separate-repositories/separate-repository-contacts/contact', [
                'sender_name' => 'Sample User',
                'sender_email' => 'sender@example.org',
                'message' => 'Please contact only the original repository.',
                'send_to_all' => false,
                'repository_contact_type' => 'original',
            ])->assertOk();

            Mail::assertQueued(ContactPersonMessage::class, fn ($mail): bool => $mail->hasTo('original@example.org'));
            Mail::assertNotQueued(ContactPersonMessage::class, fn ($mail): bool => $mail->hasTo('current@example.org'));
        });

        it('rejects a repository selector when the stored contact has no valid address', function (): void {
            Mail::fake();
            $resource = Resource::factory()->create();
            IgsnMetadata::query()->create([
                'resource_id' => $resource->id,
                'current_archive_contact' => 'Repository help desk',
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.60510/repository-without-email',
                'slug' => 'repository-without-email',
            ]);

            $this->postJson('/10.60510/repository-without-email/repository-without-email/contact', [
                'sender_name' => 'Sample User',
                'sender_email' => 'sender@example.org',
                'message' => 'This request cannot be routed without an address.',
                'send_to_all' => false,
                'repository_contact_type' => 'current',
            ])->assertUnprocessable()->assertJsonValidationErrors(['recipients']);

            Mail::assertNothingQueued();
        });

        it('rejects invalid or ambiguous repository recipient selectors', function (): void {
            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            $creator = ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'creator@example.org',
                'is_contact' => true,
            ]);
            IgsnMetadata::query()->create([
                'resource_id' => $resource->id,
                'current_archive_contact' => 'repository@example.org',
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.60510/selector-validation',
                'slug' => 'selector-validation',
            ]);
            $payload = [
                'sender_name' => 'Sample User',
                'sender_email' => 'sender@example.org',
                'message' => 'This message has an invalid recipient selector.',
                'send_to_all' => false,
            ];

            $this->postJson('/10.60510/selector-validation/selector-validation/contact', [
                ...$payload,
                'repository_contact_type' => 'attacker-controlled',
            ])->assertUnprocessable()->assertJsonValidationErrors(['repository_contact_type']);

            $this->postJson('/10.60510/selector-validation/selector-validation/contact', [
                ...$payload,
                'repository_contact_type' => 'current',
                'resource_creator_id' => $creator->id,
            ])->assertUnprocessable()->assertJsonValidationErrors(['recipients']);
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
                'is_contact' => true,
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
                'is_contact' => true,
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
                ->assertJson(['message' => 'Message received successfully.']);

            Mail::assertQueued(ContactPersonMessage::class);
        });

        it('sends a draft data request directly to the team when no contact person exists', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

            $resource = Resource::factory()->create();
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => null,
                'slug' => 'draft-team-request',
            ]);

            $this->postJson("/draft-{$resource->id}/draft-team-request/contact", [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Please provide download information for this dataset.',
                'send_to_all' => true,
            ])->assertOk()->assertJson(['recipients_count' => 1]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, fn (ContactPersonMessage $mail): bool => $mail->hasTo('datapub@gfz.de'));
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

    describe('storePreview (session-based landing page preview)', function (): void {

        it('sends a contact message for an active session preview', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'datapub@gfz.de']);

            $user = User::factory()->curator()->create();
            $resource = Resource::factory()->create([
                'created_by_user_id' => $user->id,
            ]);

            $response = $this->actingAs($user)
                ->withSession([
                    "landing_page_preview.{$resource->id}" => [
                        'template' => 'default_gfz',
                        'resource_id' => $resource->id,
                    ],
                ])
                ->postJson("/resources/{$resource->id}/landing-page/preview/contact", [
                    'sender_name' => 'Preview User',
                    'sender_email' => 'preview@example.com',
                    'message' => 'Please provide download information for this preview.',
                    'send_to_all' => true,
                ]);

            $response->assertOk()
                ->assertJson([
                    'message' => 'Message received successfully.',
                    'recipients_count' => 1,
                    'data_publication_team_direct_recipient' => true,
                ]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, fn (ContactPersonMessage $mail): bool => $mail->hasTo('datapub@gfz.de'));

            $this->assertDatabaseHas('contact_messages', [
                'resource_id' => $resource->id,
                'sender_name' => 'Preview User',
                'sender_email' => 'preview@example.com',
                'recipient_count' => 1,
            ]);
        });

        it('returns 404 when the preview session is missing', function (): void {
            Mail::fake();

            $user = User::factory()->curator()->create();
            $resource = Resource::factory()->create([
                'created_by_user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->postJson("/resources/{$resource->id}/landing-page/preview/contact", [
                    'sender_name' => 'Preview User',
                    'sender_email' => 'preview@example.com',
                    'message' => 'This preview is no longer available.',
                    'send_to_all' => true,
                ])
                ->assertNotFound();

            Mail::assertNothingQueued();
            $this->assertDatabaseCount('contact_messages', 0);
        });

        it('returns 404 when the preview session belongs to another resource', function (): void {
            Mail::fake();

            $user = User::factory()->curator()->create();
            $resource = Resource::factory()->create([
                'created_by_user_id' => $user->id,
            ]);
            $otherResource = Resource::factory()->create([
                'created_by_user_id' => $user->id,
            ]);

            $this->actingAs($user)
                ->withSession([
                    "landing_page_preview.{$resource->id}" => [
                        'template' => 'default_gfz',
                        'resource_id' => $otherResource->id,
                    ],
                ])
                ->postJson("/resources/{$resource->id}/landing-page/preview/contact", [
                    'sender_name' => 'Preview User',
                    'sender_email' => 'preview@example.com',
                    'message' => 'This preview belongs to another resource.',
                    'send_to_all' => true,
                ])
                ->assertNotFound();

            Mail::assertNothingQueued();
            $this->assertDatabaseCount('contact_messages', 0);
        });

        it('requires authentication', function (): void {
            $resource = Resource::factory()->create();

            $this->withSession([
                "landing_page_preview.{$resource->id}" => [
                    'template' => 'default_gfz',
                    'resource_id' => $resource->id,
                ],
            ])->postJson("/resources/{$resource->id}/landing-page/preview/contact", [
                'sender_name' => 'Preview User',
                'sender_email' => 'preview@example.com',
                'message' => 'This request is not authenticated.',
                'send_to_all' => true,
            ])->assertUnauthorized();
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
                'is_contact' => true,
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
                'is_contact' => true,
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

            // Verify exactly one email has Cc (consistent with multi-recipient test pattern)
            $emailsWithCc = 0;
            Mail::assertQueued(ContactPersonMessage::class, function ($mail) use (&$emailsWithCc) {
                if ($mail->hasCc('datapub@gfz.de')) {
                    $emailsWithCc++;
                }

                return true;
            });

            expect($emailsWithCc)->toBe(1, 'Exactly one email should have Cc');
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
                'is_contact' => true,
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

        it('does not add cc when config contains invalid email address', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'not-a-valid-email']);

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $person = Person::factory()->create([
                'given_name' => 'Test',
                'family_name' => 'Person',
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'test.person@example.com',
                'is_contact' => true,
            ]);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.invalid.001',
                'slug' => 'invalid-cc-test',
            ]);

            $this->postJson('/10.5880/gfz.invalid.001/invalid-cc-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'This is a test message with invalid cc config.',
                'send_to_all' => true,
            ])->assertOk();

            // Verify no Cc is added when config contains invalid email
            Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
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
                'is_contact' => true,
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
                'is_contact' => true,
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person2->id,
                'email' => 'bob@example.com',
                'is_contact' => true,
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person3->id,
                'email' => 'carol@example.com',
                'is_contact' => true,
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

        it('does not cc the team when its address is already a contact recipient', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'DataPub@GFZ.de']);

            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'datapub@gfz.de',
                'is_contact' => true,
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.team-dedup.001',
                'slug' => 'team-dedup',
            ]);

            $this->postJson('/10.5880/gfz.team-dedup.001/team-dedup/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'This request should reach the team only once.',
                'send_to_all' => true,
            ])->assertOk()->assertJson([
                'recipients_count' => 1,
                'data_publication_team_direct_recipient' => true,
            ]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, function (ContactPersonMessage $mail): bool {
                return $mail->hasTo('datapub@gfz.de') && empty($mail->cc);
            });
        });

        it('queues only one team message when distinct contacts share its normalized address', function (): void {
            Mail::fake();
            config(['mail.landing_page_contact_cc' => 'DataPub@GFZ.de']);

            $resource = Resource::factory()->create();
            $firstPerson = Person::factory()->create([
                'given_name' => 'Alice',
                'family_name' => 'First',
            ]);
            $secondPerson = Person::factory()->create([
                'given_name' => 'Bob',
                'family_name' => 'Second',
            ]);

            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $firstPerson->id,
                'email' => 'datapub@gfz.de',
                'is_contact' => true,
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $secondPerson->id,
                'email' => ' DataPub@GFZ.de ',
                'is_contact' => true,
            ]);
            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.team-email-dedup.001',
                'slug' => 'team-email-dedup',
            ]);

            $this->postJson('/10.5880/gfz.team-email-dedup.001/team-email-dedup/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'This request should reach the shared team address only once.',
                'send_to_all' => true,
            ])->assertOk()->assertJson([
                'recipients_count' => 1,
                'data_publication_team_direct_recipient' => true,
            ]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, function (ContactPersonMessage $mail): bool {
                return $mail->hasTo('datapub@gfz.de') && empty($mail->cc);
            });

            expect(ContactMessage::query()->latest('id')->firstOrFail()->recipient_count)->toBe(1);
        });

    });

    describe('contributor contact person routing', function (): void {

        it('sends message to a specific contributor contact person', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            $contactType = ContributorType::create(['name' => 'ContactPerson', 'slug' => 'ContactPerson']);

            $person = Person::factory()->create(['given_name' => 'Bob', 'family_name' => 'Contributor']);
            $contributor = ResourceContributor::factory()->create([
                'resource_id' => $resource->id,
                'contributorable_type' => Person::class,
                'contributorable_id' => $person->id,
                'email' => 'bob@example.com',
            ]);
            $contributor->contributorTypes()->attach($contactType);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.contrib.001',
                'slug' => 'contributor-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.contrib.001/contributor-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message for a contributor contact person.',
                'send_to_all' => false,
                'resource_contributor_id' => $contributor->id,
            ]);

            $response->assertOk()
                ->assertJson(['recipients_count' => 1]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, function ($mail) {
                return $mail->hasTo('bob@example.com');
            });
        });

        it('sends to all includes contributor contact persons', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            // Creator contact person
            $creatorPerson = Person::factory()->create(['given_name' => 'Alice', 'family_name' => 'Creator']);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $creatorPerson->id,
                'email' => 'alice@example.com',
                'is_contact' => true,
            ]);

            // Contributor contact person (different person)
            $contactType = ContributorType::create(['name' => 'ContactPerson', 'slug' => 'ContactPerson']);
            $contributorPerson = Person::factory()->create(['given_name' => 'Bob', 'family_name' => 'Contributor']);
            $contributor = ResourceContributor::factory()->create([
                'resource_id' => $resource->id,
                'contributorable_type' => Person::class,
                'contributorable_id' => $contributorPerson->id,
                'email' => 'bob@example.com',
            ]);
            $contributor->contributorTypes()->attach($contactType);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.allcontrib.001',
                'slug' => 'all-contrib-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.allcontrib.001/all-contrib-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message for all contact persons including contributors.',
                'send_to_all' => true,
            ]);

            $response->assertOk()
                ->assertJson(['recipients_count' => 2]);

            Mail::assertQueued(ContactPersonMessage::class, 2);
            Mail::assertQueued(ContactPersonMessage::class, fn ($mail) => $mail->hasTo('alice@example.com'));
            Mail::assertQueued(ContactPersonMessage::class, fn ($mail) => $mail->hasTo('bob@example.com'));
        });

        it('deduplicates contributor against creator when sending to all', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Test Dataset']);

            // Same person as creator (is_contact) AND contributor (ContactPerson type)
            $person = Person::factory()->create(['given_name' => 'Alice', 'family_name' => 'Duplicate']);

            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $person->id,
                'email' => 'alice@example.com',
                'is_contact' => true,
            ]);

            $contactType = ContributorType::create(['name' => 'ContactPerson', 'slug' => 'ContactPerson']);
            $contributor = ResourceContributor::factory()->create([
                'resource_id' => $resource->id,
                'contributorable_type' => Person::class,
                'contributorable_id' => $person->id,
                'email' => 'alice@example.com',
            ]);
            $contributor->contributorTypes()->attach($contactType);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.dedup.001',
                'slug' => 'dedup-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.dedup.001/dedup-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message testing dedup between creator and contributor.',
                'send_to_all' => true,
            ]);

            $response->assertOk()
                ->assertJson(['recipients_count' => 1]);

            // Only 1 email: creator preferred, contributor deduplicated
            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, fn ($mail) => $mail->hasTo('alice@example.com'));
        });

        it('deduplicates a reordered legacy contact against its creator when sending to all', function (): void {
            Mail::fake();

            $resource = Resource::factory()->create();
            Title::factory()->create(['resource_id' => $resource->id, 'value' => 'Legacy Contact Dataset']);

            $creatorPerson = Person::factory()->create([
                'given_name' => 'Juan Camilo',
                'family_name' => 'Gomez-Zapata',
                'name_identifier' => null,
                'name_identifier_scheme' => null,
            ]);
            ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_type' => Person::class,
                'creatorable_id' => $creatorPerson->id,
                'email' => 'creator@example.com',
                'is_contact' => true,
            ]);

            $contributorPerson = Person::factory()->create([
                'given_name' => 'Gomez Zapata Juan',
                'family_name' => 'Camilo',
                'name_identifier' => null,
                'name_identifier_scheme' => null,
            ]);
            $contactType = ContributorType::create(['name' => 'ContactPerson', 'slug' => 'ContactPerson']);
            $contributor = ResourceContributor::factory()->create([
                'resource_id' => $resource->id,
                'contributorable_type' => Person::class,
                'contributorable_id' => $contributorPerson->id,
                'email' => 'contributor@example.com',
            ]);
            $contributor->contributorTypes()->attach($contactType);

            LandingPage::factory()->create([
                'resource_id' => $resource->id,
                'doi_prefix' => '10.5880/gfz.legacy-dedup.001',
                'slug' => 'legacy-dedup-test',
            ]);

            $response = $this->postJson('/10.5880/gfz.legacy-dedup.001/legacy-dedup-test/contact', [
                'sender_name' => 'Test User',
                'sender_email' => 'test@example.com',
                'message' => 'Message testing legacy name-order deduplication.',
                'send_to_all' => true,
            ]);

            $response->assertOk()
                ->assertJson(['recipients_count' => 1]);

            Mail::assertQueued(ContactPersonMessage::class, 1);
            Mail::assertQueued(ContactPersonMessage::class, fn ($mail) => $mail->hasTo('creator@example.com'));
            Mail::assertNotQueued(ContactPersonMessage::class, fn ($mail) => $mail->hasTo('contributor@example.com'));
        });

    });

});
