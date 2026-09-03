<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;
use App\Mail\UserFeedbackMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

covers(UserFeedbackMail::class);

function makeUserFeedbackMail(array $overrides = []): UserFeedbackMail
{
    $arguments = array_replace([
        'feedbackId' => '123e4567-e89b-12d3-a456-426614174000',
        'category' => FeedbackCategory::PROBLEM,
        'feedbackMessage' => 'A detailed description of the problem.',
        'submittedByName' => 'Jane Doe',
        'submittedByEmail' => 'jane@example.test',
        'submittedByRole' => 'Curator',
        'submittedAt' => '2026-09-02T12:30:00+00:00',
        'userAgent' => 'Test Browser 12.3',
        'page' => ['path' => '/resources', 'title' => 'Resources — ERNIE'],
        'environment' => [
            'appearance' => 'system',
            'resolved_theme' => 'dark',
            'viewport_width' => 1440,
            'viewport_height' => 900,
            'device_pixel_ratio' => 1,
            'locale' => 'en-GB',
            'timezone' => 'Europe/Berlin',
        ],
        'diagnostics' => [[
            'type' => 'navigation',
            'occurred_at' => '2026-09-02T12:29:00.000Z',
            'path' => '/resources',
        ]],
        'recipientAdminId' => 44,
        'recipientName' => 'Admin User',
    ], $overrides);

    return new UserFeedbackMail(...$arguments);
}

it('is queued with encrypted payloads and bounded retries', function (): void {
    $mail = makeUserFeedbackMail();

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($mail->tries)->toBe(3)
        ->and($mail->backoff())->toBe([60, 300, 900]);
});

it('uses a safe subject, reply-to address, and correlation header', function (): void {
    $mail = makeUserFeedbackMail();
    $envelope = $mail->envelope();
    $email = new Email;

    foreach ($envelope->using as $callback) {
        $callback($email);
    }

    expect($envelope->subject)->toBe('[ERNIE Feedback] Problem — 123e4567')
        ->and($envelope->subject)->not->toContain('jane@example.test')
        ->and($envelope->replyTo)->toHaveCount(1)
        ->and($envelope->replyTo[0]->address)->toBe('jane@example.test')
        ->and($email->getHeaders()->get('X-ERNIE-Feedback-ID')?->getBodyAsString())->toBe($mail->feedbackId);
});

it('provides html and plain-text views with the complete context', function (): void {
    $content = makeUserFeedbackMail()->content();

    expect($content->view)->toBe('emails.user-feedback')
        ->and($content->text)->toBe('emails.user-feedback-text')
        ->and($content->with)->toMatchArray([
            'categoryLabel' => 'Problem',
            'feedbackMessage' => 'A detailed description of the problem.',
            'submittedByName' => 'Jane Doe',
            'submittedByEmail' => 'jane@example.test',
            'submittedByRole' => 'Curator',
            'userAgent' => 'Test Browser 12.3',
            'recipientName' => 'Admin User',
        ]);
});

it('escapes feedback and diagnostic html in the rendered message', function (): void {
    $mail = makeUserFeedbackMail([
        'feedbackMessage' => '<script>alert("feedback")</script>',
        'diagnostics' => [[
            'type' => 'javascript_error',
            'occurred_at' => '2026-09-02T12:29:00.000Z',
            'message' => '<img src=x onerror=alert(1)>',
        ]],
    ]);

    $rendered = $mail->render();

    expect($rendered)->not->toContain('<script>')
        ->not->toContain('<img src=x')
        ->toContain('&lt;script&gt;')
        ->toContain('&lt;img');
});

it('has no attachments', function (): void {
    expect(makeUserFeedbackMail()->attachments())->toBeEmpty();
});

it('logs delivery failures without logging feedback content or diagnostics', function (): void {
    Log::spy();
    $mail = makeUserFeedbackMail([
        'feedbackMessage' => 'Sensitive feedback body',
    ]);

    $mail->failed(new RuntimeException('SMTP unavailable'));

    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
        return $message === 'User feedback email delivery failed'
            && $context['feedback_id'] === '123e4567-e89b-12d3-a456-426614174000'
            && $context['recipient_admin_id'] === 44
            && $context['exception'] === RuntimeException::class
            && $context['error'] === 'SMTP unavailable'
            && ! array_key_exists('feedback', $context)
            && ! array_key_exists('diagnostics', $context);
    });
});

it('handles an unavailable delivery exception', function (): void {
    Log::spy();

    makeUserFeedbackMail()->failed(null);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $message === 'User feedback email delivery failed'
        && $context['exception'] === null
        && $context['error'] === null);
});
