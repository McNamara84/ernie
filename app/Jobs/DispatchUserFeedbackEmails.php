<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FeedbackCategory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class DispatchUserFeedbackEmails implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const IDEMPOTENCY_TTL_SECONDS = 604800;

    public int $tries = 3;

    public int $uniqueFor = self::IDEMPOTENCY_TTL_SECONDS;

    /**
     * @param  array{path: string, title: string}  $page
     * @param  array{appearance: string, resolved_theme: string, viewport_width: int, viewport_height: int, device_pixel_ratio: float|int, locale: string, timezone: string}  $environment
     * @param  list<array<string, int|string|null>>  $diagnostics
     * @param  list<array{id: int, name: string, email: string}>  $recipients
     */
    public function __construct(
        public readonly string $feedbackId,
        public readonly FeedbackCategory $category,
        public readonly string $feedbackMessage,
        public readonly int $submittedByUserId,
        public readonly string $submittedByName,
        public readonly string $submittedByEmail,
        public readonly string $submittedByRole,
        public readonly string $submittedAt,
        public readonly string $userAgent,
        public readonly array $page,
        public readonly array $environment,
        public readonly array $diagnostics,
        public readonly array $recipients,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return $this->feedbackId;
    }

    public function uniqueVia(): Repository
    {
        return Cache::store('database');
    }

    public function handle(): void
    {
        $cache = $this->uniqueVia();
        $markerKey = $this->idempotencyMarkerKey();

        DB::transaction(function () use ($cache, $markerKey): void {
            if ($cache->has($markerKey)) {
                return;
            }

            $jobs = array_map(fn (array $recipient): SendUserFeedbackEmail => new SendUserFeedbackEmail(
                feedbackId: $this->feedbackId,
                category: $this->category,
                feedbackMessage: $this->feedbackMessage,
                submittedByName: $this->submittedByName,
                submittedByEmail: $this->submittedByEmail,
                submittedByRole: $this->submittedByRole,
                submittedAt: $this->submittedAt,
                userAgent: $this->userAgent,
                page: $this->page,
                environment: $this->environment,
                diagnostics: $this->diagnostics,
                recipientAdminId: $recipient['id'],
                recipientName: $recipient['name'],
                recipientEmail: $recipient['email'],
            ), $this->recipients);

            Bus::batch($jobs)
                ->name("User feedback {$this->feedbackId}")
                ->allowFailures()
                ->onConnection('database')
                ->dispatch();

            // The standard database cache, batch repository, and queue share the
            // application database, so this marker commits atomically with the batch.
            $cache->put($markerKey, true, self::IDEMPOTENCY_TTL_SECONDS);
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('User feedback fan-out failed', [
            'feedback_id' => $this->feedbackId,
            'submitted_by_user_id' => $this->submittedByUserId,
            'recipient_count' => count($this->recipients),
            'exception' => $exception !== null ? $exception::class : null,
        ]);
    }

    public function idempotencyMarkerKey(): string
    {
        return "user-feedback:fan-out:{$this->feedbackId}";
    }
}
