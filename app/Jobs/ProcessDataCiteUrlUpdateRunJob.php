<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DataCiteUrlUpdateItemStatus;
use App\Enums\DataCiteUrlUpdateRunStatus;
use App\Exceptions\DataCiteRequestDeferredException;
use App\Models\DataCiteUrlUpdateItem;
use App\Models\DataCiteUrlUpdateRun;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataCiteUrlUpdateCandidateService;
use App\Services\DataCiteUrlUpdateTargetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persistent one-request-at-a-time orchestrator for a DataCite URL update run.
 */
class ProcessDataCiteUrlUpdateRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly string $runId,
    ) {}

    public function handle(
        DataCiteMemberApiClient $client,
        DataCiteRequestLimiter $limiter,
        DataCiteUrlUpdateCandidateService $candidates,
        DataCiteUrlUpdateTargetService $target,
    ): void {
        $lock = Cache::lock("datacite:url-update:run:{$this->runId}", 85);
        if (! $lock->get()) {
            $this->scheduleNext(2);

            return;
        }

        try {
            $run = DataCiteUrlUpdateRun::query()->find($this->runId);
            if ($run === null || $run->status->isTerminal() || $run->status === DataCiteUrlUpdateRunStatus::PAUSED) {
                return;
            }

            if ($run->status === DataCiteUrlUpdateRunStatus::CANCEL_REQUESTED) {
                $this->cancel($run);

                return;
            }

            if (! $this->configurationMatches($run, $client, $target)) {
                $this->pause($run, 'DataCite mode, endpoint, or APP_URL changed while the run was active.');

                return;
            }

            if ($run->status === DataCiteUrlUpdateRunStatus::QUEUED) {
                $run->update([
                    'status' => DataCiteUrlUpdateRunStatus::RUNNING,
                    'started_at' => $run->started_at ?? now(),
                    'pause_reason' => null,
                ]);
            }

            $item = DataCiteUrlUpdateItem::query()
                ->where('run_id', $run->id)
                ->whereIn('status', [
                    DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT,
                    DataCiteUrlUpdateItemStatus::PENDING_UPDATE,
                ])
                ->orderBy('id')
                ->first();

            if ($item === null) {
                $this->completeRun($run);

                return;
            }

            if ($item->status === DataCiteUrlUpdateItemStatus::PENDING_PREFLIGHT) {
                $this->preflight($run, $item, $client, $limiter, $candidates, $target);
            } else {
                $this->updateUrl($run, $item, $client, $limiter, $candidates, $target);
            }
        } finally {
            $lock->release();
        }
    }

    private function preflight(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        DataCiteMemberApiClient $client,
        DataCiteRequestLimiter $limiter,
        DataCiteUrlUpdateCandidateService $candidates,
        DataCiteUrlUpdateTargetService $target,
    ): void {
        if (! $this->locallyEligible($run, $item, $candidates, $target)) {
            $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::SKIPPED_NO_LONGER_ELIGIBLE, 'The ERNIE record is no longer eligible.');

            return;
        }

        if (! $target->isReachable($item->target_url)) {
            $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::SKIPPED_TARGET_UNREACHABLE, 'The new landing page is not reachable.');

            return;
        }

        try {
            $response = $client->getDoi($item->identifier, true);
        } catch (DataCiteRequestDeferredException $exception) {
            $this->scheduleNext((int) ceil($exception->retryAfterMilliseconds / 1000));

            return;
        } catch (ConnectionException $exception) {
            $item->increment('preflight_attempts');
            $item->refresh();
            $this->handleTransient($run, $item, null, $exception->getMessage(), true);

            return;
        }

        $item->increment('preflight_attempts');
        $item->refresh();

        if ($this->handleCommonResponseFailure($run, $item, $response, $limiter, true)) {
            return;
        }

        $beforeUrl = $response->json('data.attributes.url');
        $state = $response->json('data.attributes.state');
        $beforeUrl = is_string($beforeUrl) ? trim($beforeUrl) : '';
        $state = is_string($state) ? $state : null;

        if ($target->urlsEqual($beforeUrl, $item->target_url)) {
            $this->finishItem(
                $run,
                $item,
                DataCiteUrlUpdateItemStatus::ALREADY_CURRENT,
                null,
                $response->status(),
                $beforeUrl,
                $state,
            );

            return;
        }

        $item->update([
            'status' => DataCiteUrlUpdateItemStatus::PENDING_UPDATE,
            'before_url' => $beforeUrl,
            'datacite_state' => $state,
            'last_http_status' => $response->status(),
            'error_message' => null,
        ]);
        $this->scheduleNext();
    }

    private function updateUrl(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        DataCiteMemberApiClient $client,
        DataCiteRequestLimiter $limiter,
        DataCiteUrlUpdateCandidateService $candidates,
        DataCiteUrlUpdateTargetService $target,
    ): void {
        if (! $this->locallyEligible($run, $item, $candidates, $target)) {
            $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::SKIPPED_NO_LONGER_ELIGIBLE, 'The ERNIE record is no longer eligible.');

            return;
        }

        if (! $target->isReachable($item->target_url)) {
            $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::SKIPPED_TARGET_UNREACHABLE, 'The new landing page is not reachable.');

            return;
        }

        try {
            $response = $client->updateLandingPageUrl($item->identifier, $item->target_url, true);
        } catch (DataCiteRequestDeferredException $exception) {
            $this->scheduleNext((int) ceil($exception->retryAfterMilliseconds / 1000));

            return;
        } catch (ConnectionException $exception) {
            $item->increment('update_attempts');
            $item->refresh();
            $this->handleTransient($run, $item, null, $exception->getMessage(), false);

            return;
        }

        $item->increment('update_attempts');
        $item->refresh();

        if ($this->handleCommonResponseFailure($run, $item, $response, $limiter, false)) {
            return;
        }

        $returnedUrl = $response->json('data.attributes.url');
        if (! is_string($returnedUrl) || ! $target->urlsEqual($returnedUrl, $item->target_url)) {
            $this->finishItem(
                $run,
                $item,
                DataCiteUrlUpdateItemStatus::FAILED,
                'DataCite did not confirm the requested landing-page URL.',
                $response->status(),
            );

            return;
        }

        $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::UPDATED, null, $response->status());
    }

    private function handleCommonResponseFailure(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        Response $response,
        DataCiteRequestLimiter $limiter,
        bool $preflight,
    ): bool {
        if ($response->successful()) {
            return false;
        }

        $status = $response->status();
        $message = $this->responseMessage($response);

        if (in_array($status, [401, 403], true)) {
            $item->update(['last_http_status' => $status, 'error_message' => $message]);
            $this->pause($run, 'DataCite authentication or authorization failed.');

            return true;
        }

        if ($preflight && $status === 404) {
            $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::SKIPPED_REMOTE_MISSING, $message, $status);

            return true;
        }

        if ($status === 429 || $status === 408 || $response->serverError()) {
            if ($status === 429) {
                $limiter->imposeCooldown($this->retryAfterSeconds($response));
            }
            $this->handleTransient($run, $item, $status, $message, $preflight);

            return true;
        }

        $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::FAILED, $message, $status);

        return true;
    }

    private function handleTransient(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        ?int $status,
        string $message,
        bool $preflight,
    ): void {
        $attempts = $preflight ? $item->preflight_attempts : $item->update_attempts;
        $maxAttempts = max(1, (int) config('datacite.landing_page_url_update.max_transient_attempts', 5));
        $item->update([
            'last_http_status' => $status,
            'error_message' => $this->sanitize($message),
        ]);

        if ($attempts >= $maxAttempts) {
            if ($status === 429) {
                $this->pause($run, 'DataCite repeatedly rate-limited the run. Resume it after the cooldown.');
            } else {
                $this->finishItem($run, $item, DataCiteUrlUpdateItemStatus::FAILED, $message, $status);
            }

            return;
        }

        $delay = min(300, (5 * (2 ** max(0, $attempts - 1))) + random_int(0, 3));
        if ($status === 429) {
            $delay = max($delay, 30);
        }
        $this->scheduleNext($delay);
    }

    private function locallyEligible(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        DataCiteUrlUpdateCandidateService $candidates,
        DataCiteUrlUpdateTargetService $target,
    ): bool {
        $resource = $item->resource;
        if ($resource === null || ! $candidates->isEligible($resource, $run->scope)) {
            return false;
        }

        $landingPage = $resource->landingPage;

        return $landingPage !== null
            && $target->urlsEqual($target->buildUrl($landingPage), $item->target_url);
    }

    private function finishItem(
        DataCiteUrlUpdateRun $run,
        DataCiteUrlUpdateItem $item,
        DataCiteUrlUpdateItemStatus $status,
        ?string $message,
        ?int $httpStatus = null,
        ?string $beforeUrl = null,
        ?string $dataCiteState = null,
    ): void {
        DB::transaction(function () use ($run, $item, $status, $message, $httpStatus, $beforeUrl, $dataCiteState): void {
            $lockedItem = DataCiteUrlUpdateItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->status->isProcessed()) {
                return;
            }

            $lockedItem->update([
                'status' => $status,
                'before_url' => $beforeUrl ?? $lockedItem->before_url,
                'datacite_state' => $dataCiteState ?? $lockedItem->datacite_state,
                'last_http_status' => $httpStatus ?? $lockedItem->last_http_status,
                'error_message' => $message === null ? null : $this->sanitize($message),
                'processed_at' => now(),
            ]);

            $lockedRun = DataCiteUrlUpdateRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedRun->increment('processed');
            if ($status === DataCiteUrlUpdateItemStatus::UPDATED) {
                $lockedRun->increment('updated');
            } elseif ($status === DataCiteUrlUpdateItemStatus::ALREADY_CURRENT) {
                $lockedRun->increment('already_current');
            } elseif ($status->isSkipped()) {
                $lockedRun->increment('skipped');
            } elseif ($status === DataCiteUrlUpdateItemStatus::FAILED) {
                $lockedRun->increment('failed');
            }
        });

        $run->refresh();
        if ($run->processed >= $run->total) {
            $this->completeRun($run);
        } else {
            $this->scheduleNext();
        }
    }

    private function completeRun(DataCiteUrlUpdateRun $run): void
    {
        $run->update([
            'status' => DataCiteUrlUpdateRunStatus::COMPLETED,
            'active_marker' => null,
            'completed_at' => now(),
            'pause_reason' => null,
        ]);
    }

    private function cancel(DataCiteUrlUpdateRun $run): void
    {
        $run->update([
            'status' => DataCiteUrlUpdateRunStatus::CANCELLED,
            'active_marker' => null,
            'cancelled_at' => now(),
        ]);
    }

    private function pause(DataCiteUrlUpdateRun $run, string $reason): void
    {
        $run->update([
            'status' => DataCiteUrlUpdateRunStatus::PAUSED,
            'pause_reason' => $this->sanitize($reason),
            'paused_at' => now(),
        ]);
    }

    private function configurationMatches(
        DataCiteUrlUpdateRun $run,
        DataCiteMemberApiClient $client,
        DataCiteUrlUpdateTargetService $target,
    ): bool {
        $targetValidation = $target->validateTargetBase();

        return $targetValidation['valid']
            && $run->test_mode === $client->isTestMode()
            && rtrim($run->datacite_endpoint, '/') === rtrim($client->endpoint(), '/')
            && $target->urlsEqual($run->target_base_url, $target->targetBaseUrl());
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = trim((string) $response->header('Retry-After'));
        if ($header !== '' && ctype_digit($header)) {
            return max(1, (int) $header);
        }

        if ($header !== '') {
            $timestamp = strtotime($header);
            if ($timestamp !== false) {
                return max(1, $timestamp - time());
            }
        }

        return 60;
    }

    private function responseMessage(Response $response): string
    {
        $errors = $response->json('errors');
        if (is_array($errors) && is_array($errors[0] ?? null)) {
            $title = $errors[0]['title'] ?? $errors[0]['detail'] ?? null;
            if (is_string($title) && trim($title) !== '') {
                return $this->sanitize($title);
            }
        }

        return "DataCite returned HTTP {$response->status()}.";
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 1000);
    }

    private function scheduleNext(int $delaySeconds = 0): void
    {
        self::dispatch($this->runId)
            ->onQueue((string) config('datacite.landing_page_url_update.queue', 'datacite'))
            ->delay(now()->addSeconds(max(0, $delaySeconds)))
            ->afterCommit();
    }

    public function failed(?Throwable $exception): void
    {
        $run = DataCiteUrlUpdateRun::query()->find($this->runId);
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        Log::error('DataCite landing-page URL update job failed.', [
            'run_id' => $this->runId,
            'error' => $exception?->getMessage(),
        ]);

        $run->update([
            'status' => DataCiteUrlUpdateRunStatus::PAUSED,
            'pause_reason' => 'The queue job failed unexpectedly. Resume the run after checking the logs.',
            'last_error' => $exception === null ? null : $this->sanitize($exception->getMessage()),
            'paused_at' => now(),
        ]);
    }
}
