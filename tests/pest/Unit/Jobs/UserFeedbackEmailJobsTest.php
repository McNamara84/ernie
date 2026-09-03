<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;
use App\Jobs\DispatchUserFeedbackEmails;
use App\Jobs\SendUserFeedbackEmail;
use App\Mail\UserFeedbackMail;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

covers(DispatchUserFeedbackEmails::class, SendUserFeedbackEmail::class);

function makeFeedbackFanOutJob(): DispatchUserFeedbackEmails
{
    return new DispatchUserFeedbackEmails(
        feedbackId: '123e4567-e89b-12d3-a456-426614174000',
        category: FeedbackCategory::IDEA,
        feedbackMessage: 'A detailed improvement idea.',
        submittedByUserId: 17,
        submittedByName: 'Jane Doe',
        submittedByEmail: 'jane@example.test',
        submittedByRole: 'Curator',
        submittedAt: '2026-09-02T12:30:00+00:00',
        userAgent: 'Test Browser 12.3',
        page: ['path' => '/resources', 'title' => 'Resources — ERNIE'],
        environment: [
            'appearance' => 'system',
            'resolved_theme' => 'dark',
            'viewport_width' => 1440,
            'viewport_height' => 900,
            'device_pixel_ratio' => 1,
            'locale' => 'en-GB',
            'timezone' => 'Europe/Berlin',
        ],
        diagnostics: [[
            'type' => 'navigation',
            'occurred_at' => '2026-09-02T12:29:00.000Z',
            'path' => '/resources',
        ]],
        recipients: [
            ['id' => 41, 'name' => 'First Admin', 'email' => 'first-admin@example.test'],
            ['id' => 42, 'name' => 'Second Admin', 'email' => 'second-admin@example.test'],
        ],
    );
}

function makeFeedbackDeliveryJob(): SendUserFeedbackEmail
{
    $fanOut = makeFeedbackFanOutJob();

    return new SendUserFeedbackEmail(
        feedbackId: $fanOut->feedbackId,
        category: $fanOut->category,
        feedbackMessage: $fanOut->feedbackMessage,
        submittedByName: $fanOut->submittedByName,
        submittedByEmail: $fanOut->submittedByEmail,
        submittedByRole: $fanOut->submittedByRole,
        submittedAt: $fanOut->submittedAt,
        userAgent: $fanOut->userAgent,
        page: $fanOut->page,
        environment: $fanOut->environment,
        diagnostics: $fanOut->diagnostics,
        recipientAdminId: 41,
        recipientName: 'First Admin',
        recipientEmail: 'first-admin@example.test',
    );
}

afterEach(function (): void {
    $job = makeFeedbackFanOutJob();
    Cache::store('database')->forget($job->idempotencyMarkerKey());
});

it('uses encrypted queued jobs and a stable fan-out identity', function (): void {
    $fanOut = makeFeedbackFanOutJob();
    $delivery = makeFeedbackDeliveryJob();

    expect($fanOut)->toBeInstanceOf(ShouldQueue::class)
        ->and($fanOut)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and($fanOut)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($fanOut->uniqueId())->toBe($fanOut->feedbackId)
        ->and($fanOut->uniqueFor)->toBe(DispatchUserFeedbackEmails::IDEMPOTENCY_TTL_SECONDS)
        ->and($fanOut->backoff())->toBe([60, 300, 900])
        ->and($delivery)->toBeInstanceOf(ShouldQueue::class)
        ->and($delivery)->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(class_uses_recursive($delivery))->toHaveKey(Batchable::class)
        ->and($delivery->backoff())->toBe([60, 300, 900]);
});

it('atomically batches every recipient without cancelling siblings on delivery failure', function (): void {
    Bus::fake();
    $job = makeFeedbackFanOutJob();

    $job->handle();

    Bus::assertBatched(function (PendingBatch $batch) use ($job): bool {
        $deliveryJobs = $batch->jobs->all();

        return $batch->name === "User feedback {$job->feedbackId}"
            && $batch->connection() === 'database'
            && $batch->allowsFailures()
            && count($deliveryJobs) === 2
            && $deliveryJobs[0] instanceof SendUserFeedbackEmail
            && $deliveryJobs[0]->recipientAdminId === 41
            && $deliveryJobs[1] instanceof SendUserFeedbackEmail
            && $deliveryJobs[1]->recipientAdminId === 42;
    });
    expect(Cache::store('database')->has($job->idempotencyMarkerKey()))->toBeTrue();
});

it('does not create a second recipient batch when the fan-out job is retried', function (): void {
    Bus::fake();
    $job = makeFeedbackFanOutJob();

    $job->handle();
    $job->handle();

    Bus::assertBatchCount(1);
});

it('sends one personalized mailable from each recipient job', function (): void {
    Mail::fake();
    $job = makeFeedbackDeliveryJob();

    $job->handle();

    Mail::assertSent(UserFeedbackMail::class, 1);
    Mail::assertSent(UserFeedbackMail::class, fn (UserFeedbackMail $mail): bool => $mail->hasTo('first-admin@example.test')
        && $mail->feedbackId === $job->feedbackId
        && $mail->recipientAdminId === 41
        && $mail->recipientName === 'First Admin');
});

it('logs permanent fan-out and recipient failures without feedback content', function (): void {
    Log::spy();
    $fanOut = makeFeedbackFanOutJob();
    $delivery = makeFeedbackDeliveryJob();

    $fanOut->failed(new RuntimeException('Queue unavailable'));
    $delivery->failed(new RuntimeException('SMTP unavailable'));

    Log::shouldHaveReceived('error')->with(
        'User feedback fan-out failed',
        Mockery::on(fn (array $context): bool => $context['feedback_id'] === $fanOut->feedbackId
            && $context['submitted_by_user_id'] === 17
            && $context['recipient_count'] === 2
            && ! array_key_exists('feedback', $context)
            && ! array_key_exists('diagnostics', $context)),
    )->once();
    Log::shouldHaveReceived('error')->with(
        'User feedback email delivery failed',
        Mockery::on(fn (array $context): bool => $context['feedback_id'] === $delivery->feedbackId
            && $context['recipient_admin_id'] === 41
            && ! array_key_exists('feedback', $context)
            && ! array_key_exists('diagnostics', $context)),
    )->once();
});
