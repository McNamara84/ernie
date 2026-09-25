<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AssessmentRunStatus;
use App\Exceptions\FujiAssessmentException;
use App\Models\AssessmentRun;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceAssessmentRefresh;
use App\Services\Assessment\FujiAssessmentRequestLimiterService;
use App\Services\Assessment\FujiAssessmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Reassess a DOI once its newly published landing page is the resolver target. */
final class RefreshPublishedResourceAssessmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $resourceId)
    {
        $this->timeout = max(30, (int) config('fuji.assessment.item_timeout_seconds', 330));
    }

    public function handle(FujiAssessmentService $fuji, FujiAssessmentRequestLimiterService $limiter): void
    {
        $claimed = $this->claim();
        if ($claimed === null) {
            return;
        }

        [$generation, $requestedAt, $claimToken] = $claimed;
        $resource = Resource::query()->with('landingPage.externalDomain')->find($this->resourceId);
        $page = $resource?->landingPage;
        $identifier = trim((string) $resource?->doi);

        if ($page === null || ! $page->is_published || $identifier === '') {
            $this->finish($generation, $claimToken, ResourceAssessmentRefresh::FAILED, 'The published landing page or DOI is no longer available.');

            return;
        }

        $assessment = ResourceAssessment::query()->where('resource_id', $this->resourceId)->first();
        if ($assessment?->status === ResourceAssessment::STATUS_COMPLETED
            // Timestamps are persisted with second precision. Equality may
            // mean the assessment started just before publication.
            && $assessment->assessed_at?->greaterThan($requestedAt)
            && $assessment->assessed_identifier === $identifier) {
            $this->finish($generation, $claimToken, ResourceAssessmentRefresh::COMPLETED);

            return;
        }

        if (AssessmentRun::query()->whereNotNull('active_scope')->whereIn('status', [
            AssessmentRunStatus::PREPARING->value,
            AssessmentRunStatus::QUEUED->value,
            AssessmentRunStatus::RUNNING->value,
        ])->exists()) {
            $this->defer($generation, $claimToken, 60, 'Waiting for the active resource assessment run.');

            return;
        }

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(10)
                ->head('https://doi.org/'.str_replace(['?', '#'], ['%3F', '%23'], ltrim($identifier, '/')));

            $resolverTarget = $response->header('Location');
            if (! $response->redirect() || trim($resolverTarget) === ''
                || ! $this->sameUrl($resolverTarget, $page->public_url)) {
                $this->retryOrFail($generation, $claimToken, 300, 12, 'The DOI resolver does not yet point to the published landing page.');

                return;
            }
        } catch (Throwable $exception) {
            $this->retryOrFail($generation, $claimToken, 300, 12, 'The DOI resolver could not be checked.');

            return;
        }

        $waitMs = $limiter->reserveSlot();
        if ($waitMs > 0) {
            $this->defer($generation, $claimToken, max(1, (int) ceil($waitMs / 1000)), 'Waiting for the shared F-UJI rate limit.');

            return;
        }

        $startedAt = now();
        $incremented = ResourceAssessmentRefresh::query()
            ->whereKey($this->resourceId)
            ->where('generation', $generation)
            ->where('status', ResourceAssessmentRefresh::PROCESSING)
            ->where('claim_token', $claimToken)
            ->increment('service_attempts');
        if ($incremented === 0) {
            return;
        }

        try {
            $result = $fuji->assessIdentifier($identifier);
        } catch (FujiAssessmentException $exception) {
            if ($exception->httpStatus === 429) {
                $limiter->imposeCooldown($exception->retryAfterSeconds ?? 60);
            }

            $this->retryOrFail(
                $generation,
                $claimToken,
                $exception->retryAfterSeconds ?? 60,
                $exception->retryable ? 3 : 1,
                $exception->getMessage(),
                'service_attempts',
            );

            return;
        } catch (Throwable $exception) {
            Log::warning('Published resource assessment refresh failed', [
                'resource_id' => $this->resourceId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $this->retryOrFail($generation, $claimToken, 60, 3, 'An unexpected assessment error occurred.', 'service_attempts');

            return;
        }

        DB::transaction(function () use ($generation, $requestedAt, $claimToken, $identifier, $result, $startedAt): void {
            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($this->resourceId);
            if ($refresh === null || ! $this->ownsClaim($refresh, $generation, $claimToken)) {
                return;
            }

            $current = Resource::query()->with('landingPage')->find($this->resourceId);
            if ($current?->doi !== $identifier || ! $current->landingPage?->is_published) {
                $this->resetPending($refresh, 60, 'The resource changed during assessment.');

                return;
            }

            // A complete run may have produced a newer result while this call was running.
            $existing = ResourceAssessment::query()->where('resource_id', $this->resourceId)->lockForUpdate()->first();
            if ($existing?->status !== ResourceAssessment::STATUS_COMPLETED
                || $existing->assessed_at === null || $existing->assessed_at->lessThanOrEqualTo($startedAt)) {
                ResourceAssessment::query()->updateOrCreate(['resource_id' => $this->resourceId], [
                    'status' => ResourceAssessment::STATUS_COMPLETED,
                    'failure_type' => null,
                    'error_code' => null,
                    'total_score' => $result['score'],
                    'assessed_identifier' => $identifier,
                    'error_message' => null,
                    'payload' => $result['payload'],
                    'assessed_at' => $startedAt,
                ]);
            }

            if ($startedAt->lessThan($requestedAt)) {
                $this->resetPending($refresh, 60, 'The landing page changed during assessment.');

                return;
            }

            $refresh->forceFill([
                'status' => ResourceAssessmentRefresh::COMPLETED,
                'claim_token' => null,
                'available_at' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
                'last_error' => null,
            ])->save();
        }, 3);
    }

    /** @return array{int, Carbon, string}|null */
    private function claim(): ?array
    {
        return DB::transaction(function (): ?array {
            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($this->resourceId);
            if ($refresh === null || $refresh->status === ResourceAssessmentRefresh::COMPLETED
                || $refresh->status === ResourceAssessmentRefresh::FAILED
                || ($refresh->status === ResourceAssessmentRefresh::PENDING && $refresh->available_at?->isFuture())
                || ($refresh->status === ResourceAssessmentRefresh::QUEUED && $refresh->lease_expires_at?->isPast())
                || ($refresh->status === ResourceAssessmentRefresh::PROCESSING && $refresh->lease_expires_at?->isFuture())) {
                return null;
            }

            $claimToken = Str::uuid()->toString();
            $refresh->forceFill([
                'status' => ResourceAssessmentRefresh::PROCESSING,
                'claim_token' => $claimToken,
                'attempts' => $refresh->attempts + 1,
                'available_at' => null,
                'lease_expires_at' => now()->addSeconds(max($this->timeout + 30, (int) config('fuji.assessment.lease_seconds', 390))),
            ])->save();

            return [$refresh->generation, $refresh->requested_at, $claimToken];
        }, 3);
    }

    private function defer(int $generation, string $claimToken, int $seconds, string $message): void
    {
        DB::transaction(function () use ($generation, $claimToken, $seconds, $message): void {
            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($this->resourceId);
            if ($refresh === null || ! $this->ownsClaim($refresh, $generation, $claimToken)) {
                return;
            }

            $this->resetPending($refresh, $seconds, $message);
        }, 3);
    }

    private function retryOrFail(int $generation, string $claimToken, int $seconds, int $maxAttempts, string $message, string $counter = 'attempts'): void
    {
        DB::transaction(function () use ($generation, $claimToken, $seconds, $maxAttempts, $message, $counter): void {
            $refresh = ResourceAssessmentRefresh::query()->lockForUpdate()->find($this->resourceId);
            if ($refresh === null || ! $this->ownsClaim($refresh, $generation, $claimToken)) {
                return;
            }

            if ($refresh->{$counter} < $maxAttempts) {
                $this->resetPending($refresh, $seconds, $message);

                return;
            }

            $refresh->forceFill([
                'status' => ResourceAssessmentRefresh::FAILED,
                'claim_token' => null,
                'available_at' => null,
                'lease_expires_at' => null,
                'last_error' => $message,
            ])->save();
        }, 3);
    }

    private function finish(int $generation, string $claimToken, string $status, ?string $error = null): void
    {
        ResourceAssessmentRefresh::query()
            ->whereKey($this->resourceId)
            ->where('generation', $generation)
            ->where('status', ResourceAssessmentRefresh::PROCESSING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => $status,
                'claim_token' => null,
                'available_at' => null,
                'lease_expires_at' => null,
                'completed_at' => $status === ResourceAssessmentRefresh::COMPLETED ? now() : null,
                'last_error' => $error,
            ]);
    }

    private function resetPending(ResourceAssessmentRefresh $refresh, int $seconds, string $message): void
    {
        $refresh->forceFill([
            'status' => ResourceAssessmentRefresh::PENDING,
            'claim_token' => null,
            'attempts' => str_starts_with($message, 'Waiting for ') ? max(0, $refresh->attempts - 1) : $refresh->attempts,
            'available_at' => now()->addSeconds($seconds),
            'lease_expires_at' => null,
            'last_error' => $message,
        ])->save();
    }

    private function ownsClaim(ResourceAssessmentRefresh $refresh, int $generation, string $claimToken): bool
    {
        return $refresh->generation === $generation
            && $refresh->status === ResourceAssessmentRefresh::PROCESSING
            && $refresh->claim_token === $claimToken;
    }

    private function sameUrl(string $actual, string $expected): bool
    {
        $normalize = static function (string $url): ?string {
            $parts = parse_url(trim($url));
            if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
                return null;
            }

            return strtolower($parts['scheme'].'://'.$parts['host'])
                .(isset($parts['port']) ? ':'.$parts['port'] : '')
                .rtrim(rawurldecode($parts['path'] ?? '/'), '/')
                .(isset($parts['query']) ? '?'.$parts['query'] : '');
        };

        return $normalize($actual) !== null && $normalize($actual) === $normalize($expected);
    }
}
