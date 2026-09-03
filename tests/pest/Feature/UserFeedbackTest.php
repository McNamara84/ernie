<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;
use App\Enums\UserRole;
use App\Http\Controllers\UserFeedbackController;
use App\Http\Requests\StoreUserFeedbackRequest;
use App\Mail\UserFeedbackMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

covers(UserFeedbackController::class, StoreUserFeedbackRequest::class);

/**
 * @return array<string, mixed>
 */
function validUserFeedbackPayload(array $overrides = []): array
{
    $payload = array_replace_recursive([
        'category' => FeedbackCategory::PROBLEM->value,
        'message' => 'The resource filter reset after returning from the editor.',
        'page' => [
            'path' => '/resources',
            'title' => 'Resources — ERNIE',
        ],
        'environment' => [
            'appearance' => 'system',
            'resolved_theme' => 'dark',
            'viewport_width' => 1440,
            'viewport_height' => 900,
            'device_pixel_ratio' => 1.25,
            'locale' => 'en-GB',
            'timezone' => 'Europe/Berlin',
        ],
        'diagnostics' => [
            [
                'type' => 'navigation',
                'occurred_at' => '2026-09-02T12:30:00.000Z',
                'path' => '/resources',
            ],
            [
                'type' => 'http_error',
                'occurred_at' => '2026-09-02T12:31:10.000Z',
                'method' => 'GET',
                'path' => '/resources/filter-options',
                'status' => 500,
                'message' => 'Failed for jane@example.test at https://example.test/path?token=secret',
            ],
            [
                'type' => 'javascript_error',
                'occurred_at' => '2026-09-02T12:31:12.000Z',
                'message' => 'TypeError: Unexpected value',
            ],
        ],
    ], $overrides);

    if (array_key_exists('diagnostics', $overrides)) {
        $payload['diagnostics'] = $overrides['diagnostics'];
    }

    return $payload;
}

it('requires authentication', function (): void {
    $this->postJson('/feedback', validUserFeedbackPayload())
        ->assertUnauthorized();
});

it('rejects inactive submitters', function (): void {
    $user = User::factory()->create(['is_active' => false]);

    $this->actingAs($user)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertForbidden();
});

it('queues separate encrypted feedback mail for every active admin', function (UserRole $role): void {
    Mail::fake();

    $submitter = User::factory()->create([
        'name' => 'Feedback Sender',
        'email' => 'sender@example.test',
        'role' => $role,
    ]);
    $firstAdmin = User::factory()->admin()->create(['email' => 'first-admin@example.test']);
    $secondAdmin = User::factory()->admin()->create(['email' => 'second-admin@example.test']);
    User::factory()->admin()->create(['email' => 'inactive-admin@example.test', 'is_active' => false]);
    User::factory()->create(['role' => UserRole::CURATOR, 'email' => 'curator@example.test']);

    $response = $this->actingAs($submitter)
        ->withHeader('User-Agent', 'Test Browser 12.3')
        ->postJson('/feedback', validUserFeedbackPayload());

    $response->assertAccepted()
        ->assertJsonPath('message', 'Feedback submitted.')
        ->assertJsonStructure(['feedback_id']);

    $feedbackId = (string) $response->json('feedback_id');
    expect($feedbackId)->toBeUuid();

    $expectedAdmins = $role === UserRole::ADMIN ? [$submitter, $firstAdmin, $secondAdmin] : [$firstAdmin, $secondAdmin];
    Mail::assertQueued(UserFeedbackMail::class, count($expectedAdmins));
    foreach ($expectedAdmins as $admin) {
        Mail::assertQueued(UserFeedbackMail::class, function (UserFeedbackMail $mail) use ($admin, $feedbackId): bool {
            return $mail->hasTo($admin->email)
                && $mail->feedbackId === $feedbackId
                && $mail->submittedByName === 'Feedback Sender'
                && $mail->submittedByEmail === 'sender@example.test'
                && $mail->submittedByRole !== ''
                && $mail->userAgent === 'Test Browser 12.3'
                && str_contains((string) $mail->diagnostics[1]['message'], '[redacted-email]')
                && ! str_contains((string) $mail->diagnostics[1]['message'], 'token=secret');
        });
    }
    Mail::assertNotQueued(UserFeedbackMail::class, fn (UserFeedbackMail $mail): bool => $mail->hasTo('inactive-admin@example.test'));
    Mail::assertNotQueued(UserFeedbackMail::class, fn (UserFeedbackMail $mail): bool => $mail->hasTo('curator@example.test'));
})->with([
    'admin' => UserRole::ADMIN,
    'group leader' => UserRole::GROUP_LEADER,
    'curator' => UserRole::CURATOR,
    'beginner' => UserRole::BEGINNER,
]);

it('sends one message to an administrator who submits feedback', function (): void {
    Mail::fake();
    $admin = User::factory()->admin()->create(['email' => 'submitting-admin@example.test']);

    $this->actingAs($admin)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertAccepted();

    Mail::assertQueued(UserFeedbackMail::class, 1);
    Mail::assertQueued(UserFeedbackMail::class, fn (UserFeedbackMail $mail): bool => $mail->hasTo($admin->email));
});

it('returns a service error when no active administrator exists', function (): void {
    Mail::fake();
    Log::spy();
    $submitter = User::factory()->create(['role' => UserRole::CURATOR]);
    User::factory()->admin()->create(['is_active' => false]);

    $this->actingAs($submitter)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Feedback cannot be submitted right now because no administrator is available.');

    Mail::assertNothingQueued();
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $message === 'User feedback could not be queued because no active administrators exist'
        && $context === ['submitted_by_user_id' => $submitter->id]);
});

it('returns a safe service error when queue dispatch fails', function (): void {
    Log::spy();
    $submitter = User::factory()->create(['role' => UserRole::CURATOR]);
    User::factory()->admin()->create();

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('SMTP secret detail'));

    $this->actingAs($submitter)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertServiceUnavailable()
        ->assertJsonPath('message', 'Feedback could not be submitted. Please try again.');

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'User feedback queue dispatch failed'
            && isset($context['feedback_id'], $context['submitted_by_user_id'], $context['recipient_count'])
            && ! array_key_exists('feedback', $context)
            && ! array_key_exists('diagnostics', $context);
    });
});

it('validates the closed feedback payload and diagnostic event variants', function (array $payload, array $errors): void {
    Mail::fake();
    $submitter = User::factory()->create();
    User::factory()->admin()->create();

    $this->actingAs($submitter)
        ->postJson('/feedback', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($errors);

    Mail::assertNothingQueued();
})->with([
    'unknown category' => [validUserFeedbackPayload(['category' => 'angry']), ['category']],
    'short message' => [validUserFeedbackPayload(['message' => 'Too short']), ['message']],
    'absolute page URL' => [validUserFeedbackPayload(['page' => ['path' => 'https://example.test/resources']]), ['page.path']],
    'page query' => [validUserFeedbackPayload(['page' => ['path' => '/resources?search=secret']]), ['page.path']],
    'invalid appearance' => [validUserFeedbackPayload(['environment' => ['appearance' => 'sepia']]), ['environment.appearance']],
    'too many events' => [validUserFeedbackPayload(['diagnostics' => array_fill(0, 11, [
        'type' => 'navigation',
        'occurred_at' => '2026-09-02T12:30:00.000Z',
        'path' => '/resources',
    ])]), ['diagnostics']],
    'navigation missing path' => [validUserFeedbackPayload(['diagnostics' => [[
        'type' => 'navigation',
        'occurred_at' => '2026-09-02T12:30:00.000Z',
    ]]]), ['diagnostics.0.path']],
    'javascript error missing message' => [validUserFeedbackPayload(['diagnostics' => [[
        'type' => 'javascript_error',
        'occurred_at' => '2026-09-02T12:30:00.000Z',
    ]]]), ['diagnostics.0.message']],
    'variant-specific extra key' => [validUserFeedbackPayload(['diagnostics' => [[
        'type' => 'navigation',
        'occurred_at' => '2026-09-02T12:30:00.000Z',
        'path' => '/resources',
        'status' => 200,
    ]]]), ['diagnostics.0.status']],
    'unknown nested environment key' => [validUserFeedbackPayload(['environment' => ['secret' => 'value']]), ['environment']],
    'forged submitter identity' => [validUserFeedbackPayload(['submitted_by_email' => 'forged@example.test']), ['submitted_by_email']],
]);

it('trims feedback and accepts an empty diagnostic history', function (): void {
    Mail::fake();
    $submitter = User::factory()->create();
    User::factory()->admin()->create();

    $this->actingAs($submitter)
        ->postJson('/feedback', validUserFeedbackPayload([
            'message' => '   Useful feedback with surrounding space.   ',
            'diagnostics' => [],
        ]))
        ->assertAccepted();

    Mail::assertQueued(UserFeedbackMail::class, fn (UserFeedbackMail $mail): bool => $mail->feedbackMessage === 'Useful feedback with surrounding space.'
        && $mail->diagnostics === []);
});

it('rate limits repeated submissions independently per user', function (): void {
    Mail::fake();
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    User::factory()->admin()->create();

    foreach (range(1, 5) as $_) {
        $this->actingAs($firstUser)
            ->postJson('/feedback', validUserFeedbackPayload())
            ->assertAccepted();
    }

    $this->actingAs($firstUser)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    $this->actingAs($secondUser)
        ->postJson('/feedback', validUserFeedbackPayload())
        ->assertAccepted();
});
