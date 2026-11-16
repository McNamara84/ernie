<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\IgsnRegistrationItemStatus;
use App\Enums\IgsnRegistrationRunStatus;
use App\Models\IgsnMetadata;
use App\Models\IgsnRegistrationItem;
use App\Models\IgsnRegistrationRun;
use App\Models\Resource;
use App\Services\DataCiteModeResolverService;
use App\Services\DataCiteRegistrationFactoryService;
use App\Services\IgsnRegistrationExclusionService;
use App\Services\IgsnRegistrationRunService;
use App\Services\ResourceDoiPublicationService;
use App\Services\UserActivityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one IGSN registration item per invocation and then schedules the
 * next short-lived step on the persistent DataCite queue.
 */
class ProcessIgsnRegistrationRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(
        public readonly string $runId,
    ) {}

    public function handle(
        DataCiteRegistrationFactoryService $registrations,
        DataCiteModeResolverService $modeResolver,
        IgsnRegistrationRunService $runs,
        IgsnRegistrationExclusionService $exclusion,
    ): void {
        $lock = Cache::lock(
            "igsn:registration:run:{$this->runId}",
            IgsnRegistrationExclusionService::LOCK_TTL_SECONDS,
        );
        if (! $lock->get()) {
            $this->scheduleNext(2);

            return;
        }

        try {
            $run = IgsnRegistrationRun::query()->find($this->runId);
            if ($run === null || $run->status->isTerminal() || $run->status === IgsnRegistrationRunStatus::PAUSED) {
                return;
            }

            if ($run->status === IgsnRegistrationRunStatus::CANCEL_REQUESTED) {
                $this->cancel($run, $runs);

                return;
            }

            if (! $this->configurationMatches($run, $registrations, $modeResolver)) {
                $this->pause($run, 'DataCite environment, repository or endpoint changed while the IGSN registration run was active.');

                return;
            }

            if ($run->status !== IgsnRegistrationRunStatus::RUNNING) {
                $run->update([
                    'status' => IgsnRegistrationRunStatus::RUNNING,
                    'started_at' => $run->started_at ?? now(),
                    'pause_reason' => null,
                ]);
            }

            $item = IgsnRegistrationItem::query()
                ->where('run_id', $run->id)
                ->where('status', IgsnRegistrationItemStatus::PENDING)
                ->orderBy('id')
                ->first();

            if ($item === null) {
                if ($run->processed >= $run->total) {
                    $this->complete($run);
                } else {
                    $this->pause($run, 'No pending registration item was available although the run is incomplete.');
                }

                return;
            }

            $resourceLock = $item->resource_id === null ? null : $exclusion->resourceLock($item->resource_id);
            if ($resourceLock !== null && ! $resourceLock->get()) {
                $this->scheduleNext(2);

                return;
            }

            try {
                $item->update([
                    'status' => IgsnRegistrationItemStatus::PROCESSING,
                    'attempts' => $item->attempts + 1,
                    'error_message' => null,
                    'last_http_status' => null,
                    'processed_at' => null,
                ]);

                $this->processItem($run, $item, $registrations);
                $this->advance($run);
            } finally {
                $resourceLock?->release();
            }
        } finally {
            $lock->release();
        }
    }

    private function processItem(
        IgsnRegistrationRun $run,
        IgsnRegistrationItem $item,
        DataCiteRegistrationFactoryService $registrations,
    ): void {
        $resource = $item->resource_id === null
            ? null
            : Resource::query()->with(Resource::DATACITE_EXPORT_RELATIONS)->find($item->resource_id);

        if (! $resource instanceof Resource || $resource->igsnMetadata === null) {
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, 'IGSN not found.');

            return;
        }

        if (trim((string) $resource->doi) !== $item->identifier) {
            $message = 'The IGSN identifier changed after this registration run was queued.';
            $this->markMetadataError($resource->igsnMetadata, $message);
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);

            return;
        }

        $metadata = $resource->igsnMetadata;
        if ($resource->landingPage === null) {
            $message = 'No landing page configured.';
            $this->markMetadataError($metadata, $message);
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);

            return;
        }

        $operation = $item->operation ?? ($metadata->isRegistered() ? 'update' : 'register');
        if ($operation === 'register' && $item->approval_fingerprint !== ResourceDoiPublicationService::approvalFingerprint($resource)
            && ! $resource->latestDoiPublication()->whereIn('status', ['remote_write_started', 'pending_reconciliation'])->exists()) {
            $message = 'The approved metadata or landing page changed after this registration was queued. Start a new confirmed registration.';
            $this->markMetadataError($metadata, $message);
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);

            return;
        }
        $item->update(['operation' => $operation]);
        $service = $registrations->forMode($run->test_mode);
        $originalPublicationYear = $resource->publication_year;

        try {
            $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERING);
            $response = $operation === 'update'
                ? $service->updateMetadata($resource)
                : $service->registerIgsn($resource, $run->initiated_by_user_id);
            if (($response['complete'] ?? true) !== true) {
                $message = 'DOI publication needs reconciliation. Resume registration to check the saved attempt.';
                $this->markMetadataError($metadata, $message);
                $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);

                return;
            }
            $resource->refresh();

            $metadata->updateStatus(IgsnMetadata::STATUS_REGISTERED);

            $status = $operation === 'update'
                ? IgsnRegistrationItemStatus::UPDATED
                : IgsnRegistrationItemStatus::REGISTERED;
            $this->finishItem($run, $item, $status);

            Log::info('Queued IGSN registration item completed.', [
                'run_id' => $run->id,
                'item_id' => $item->id,
                'resource_id' => $resource->id,
                'identifier' => $resource->doi,
                'operation' => $operation,
            ]);
        } catch (RequestException $exception) {
            $resource->publication_year = $originalPublicationYear;
            $response = $exception->response;
            $status = $response->status();
            $message = $this->responseMessage($response->json(), 'Failed to communicate with DataCite API.');
            $this->markMetadataError($metadata, $message);
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message, $status);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $resource->publication_year = $originalPublicationYear;
            $message = $this->sanitize($exception->getMessage());
            $this->markMetadataError($metadata, $message);
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);
        } catch (Throwable $exception) {
            $resource->publication_year = $originalPublicationYear;
            $message = config('app.debug')
                ? $this->sanitize($exception->getMessage())
                : 'An unexpected error occurred during registration.';
            $this->markMetadataError($metadata, 'An unexpected error occurred during registration.');
            $this->finishItem($run, $item, IgsnRegistrationItemStatus::FAILED, $message);

            Log::error('Queued IGSN registration item failed unexpectedly.', [
                'run_id' => $run->id,
                'item_id' => $item->id,
                'resource_id' => $resource->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function finishItem(
        IgsnRegistrationRun $run,
        IgsnRegistrationItem $item,
        IgsnRegistrationItemStatus $status,
        ?string $message = null,
        ?int $httpStatus = null,
    ): void {
        DB::transaction(function () use ($run, $item, $status, $message, $httpStatus): void {
            $lockedItem = IgsnRegistrationItem::query()->lockForUpdate()->findOrFail($item->id);
            if ($lockedItem->status !== IgsnRegistrationItemStatus::PROCESSING) {
                return;
            }

            $lockedItem->update([
                'status' => $status,
                'last_http_status' => $httpStatus,
                'error_message' => $message === null ? null : $this->sanitize($message),
                'processed_at' => now(),
            ]);

            if (in_array($status, [IgsnRegistrationItemStatus::REGISTERED, IgsnRegistrationItemStatus::UPDATED], true)) {
                $resource = Resource::find($lockedItem->resource_id);
                if ($resource !== null) {
                    $activities = app(UserActivityService::class);
                    $activities->record($run->activity_actor ?? $activities->actor($run->initiated_by_user_id), 'igsnregistration.completed',
                        $status === IgsnRegistrationItemStatus::REGISTERED ? 'registered at DataCite' : 'updated DataCite metadata for', $activities->subject($resource), operationId: (string) $run->id, testMode: $run->test_mode);
                }
            }

            $lockedRun = IgsnRegistrationRun::query()->lockForUpdate()->findOrFail($run->id);
            $lockedRun->increment('processed');
            if ($status === IgsnRegistrationItemStatus::REGISTERED) {
                $lockedRun->increment('registered');
            } elseif ($status === IgsnRegistrationItemStatus::UPDATED) {
                $lockedRun->increment('updated');
            } elseif ($status === IgsnRegistrationItemStatus::FAILED) {
                $lockedRun->increment('failed');
            }
        });
    }

    private function advance(IgsnRegistrationRun $run): void
    {
        $run->refresh();

        if ($run->status === IgsnRegistrationRunStatus::CANCEL_REQUESTED) {
            $this->scheduleNext();

            return;
        }

        if ($run->processed >= $run->total) {
            $this->complete($run);

            return;
        }

        $this->scheduleNext();
    }

    private function complete(IgsnRegistrationRun $run): void
    {
        $recordSummary = $run->completed_at === null && $run->registered + $run->updated > 0;
        $run->update([
            'status' => IgsnRegistrationRunStatus::COMPLETED,
            'completed_at' => now(),
            'pause_reason' => null,
        ]);
        if ($recordSummary) {
            $activities = app(UserActivityService::class);
            $activities->summary($run->activity_actor ?? $activities->actor($run->initiated_by_user_id), 'IGSN registration', (string) $run->id,
                ['registered' => $run->registered, 'updated' => $run->updated, 'failed' => $run->failed, 'cancelled' => $run->cancelled], $run->test_mode);
        }
    }

    private function cancel(IgsnRegistrationRun $run, IgsnRegistrationRunService $runs): void
    {
        IgsnRegistrationItem::query()
            ->where('run_id', $run->id)
            ->whereIn('status', [
                IgsnRegistrationItemStatus::PENDING,
                IgsnRegistrationItemStatus::PROCESSING,
            ])
            ->update([
                'status' => IgsnRegistrationItemStatus::CANCELLED,
                'processed_at' => now(),
            ]);

        $runs->recalculate($run);
        $run->update([
            'status' => IgsnRegistrationRunStatus::CANCELLED,
            'cancelled_at' => now(),
            'pause_reason' => null,
        ]);
        if ($run->registered + $run->updated > 0) {
            $activities = app(UserActivityService::class);
            $activities->summary($run->activity_actor ?? $activities->actor($run->initiated_by_user_id), 'IGSN registration (cancelled)', (string) $run->id,
                ['registered' => $run->registered, 'updated' => $run->updated, 'failed' => $run->failed, 'cancelled' => $run->cancelled], $run->test_mode);
        }
    }

    private function pause(IgsnRegistrationRun $run, string $reason): void
    {
        $run->update([
            'status' => IgsnRegistrationRunStatus::PAUSED,
            'pause_reason' => $reason,
            'paused_at' => now(),
        ]);
    }

    private function configurationMatches(
        IgsnRegistrationRun $run,
        DataCiteRegistrationFactoryService $registrations,
        DataCiteModeResolverService $modeResolver,
    ): bool {
        $initiator = $run->initiatedBy()->first();
        if ($initiator !== null && $modeResolver->shouldUseTestMode($initiator) !== $run->test_mode) {
            return false;
        }

        $client = $registrations->clientForMode($run->test_mode);

        return $run->datacite_context_fingerprint === $client->contextFingerprint()
            && rtrim($client->endpoint(), '/') === rtrim($run->datacite_endpoint, '/');
    }

    private function responseMessage(mixed $responseData, string $fallback): string
    {
        if (! is_array($responseData) || ! isset($responseData['errors']) || ! is_array($responseData['errors'])) {
            return $fallback;
        }

        $first = $responseData['errors'][0] ?? null;
        if (! is_array($first)) {
            return $fallback;
        }

        $message = $first['title'] ?? $first['detail'] ?? null;

        return is_string($message) && trim($message) !== '' ? $this->sanitize($message) : $fallback;
    }

    private function markMetadataError(IgsnMetadata $metadata, string $message): void
    {
        try {
            $metadata->markAsError($this->sanitize($message));
        } catch (Throwable $exception) {
            Log::warning('Could not persist the IGSN registration error status.', [
                'resource_id' => $metadata->resource_id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function scheduleNext(int $delaySeconds = 0): void
    {
        self::dispatch($this->runId)
            ->onQueue((string) config('datacite.queue', 'datacite'))
            ->delay(now()->addSeconds(max(0, $delaySeconds)))
            ->afterCommit();
    }

    private function sanitize(string $message): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $message) ?? $message), 0, 1000);
    }

    public function failed(?Throwable $exception): void
    {
        $run = IgsnRegistrationRun::query()->find($this->runId);
        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        IgsnRegistrationItem::query()
            ->where('run_id', $run->id)
            ->where('status', IgsnRegistrationItemStatus::PROCESSING)
            ->update(['status' => IgsnRegistrationItemStatus::PENDING]);

        $run->update([
            'status' => IgsnRegistrationRunStatus::PAUSED,
            'pause_reason' => 'The queue job failed unexpectedly. Resume the run after checking the logs.',
            'last_error' => $exception === null ? null : $this->sanitize($exception->getMessage()),
            'paused_at' => now(),
        ]);

        Log::error('IGSN registration queue job failed.', [
            'run_id' => $run->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
