<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\DataCiteVerificationException;
use App\Models\Resource;
use App\Services\DataCiteMemberApiClient;
use App\Services\DataCiteRegistrationFactoryService;
use App\Services\DataCiteRequestLimiter;
use App\Services\DataEmbargoReleaseService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Retry only access metadata of an existing registration, never create/publish. */
final class SyncDataEmbargoAccessJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    private ?string $dataCiteContext = null;

    public function __construct(public readonly int $resourceId, public readonly bool $testMode)
    {
        $this->dataCiteContext = app(DataCiteRegistrationFactoryService::class)->clientForMode($testMode)->contextFingerprint();
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(DataEmbargoReleaseService $service, DataCiteRequestLimiter $limiter): void
    {
        $resource = Resource::find($this->resourceId);
        if ($resource === null || ! $resource->data_embargo_sync_pending) {
            return;
        }
        $client = new DataCiteMemberApiClient($limiter, $this->testMode);
        if ($this->dataCiteContext !== $client->contextFingerprint()) {
            return;
        }
        try {
            $service->synchronize($resource, $client);
        } catch (DataCiteVerificationException $exception) {
            if ($exception->retryable) {
                throw $exception;
            }
            // Keep the pending flag, but do not retry a missing registration or
            // an unpublished page until a curator resolves that prerequisite.
        }
    }
}
