<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Exceptions\DataCiteWriteSupersededException;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DataEmbargoReleaseService
{
    public function release(Resource $resource): Resource
    {
        $testMode = app(DataCiteMemberApiClient::class)->isTestMode();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $doi = Resource::query()->findOrFail($resource->id)->doi;
            $release = fn (): ?Resource => DB::transaction(function () use ($resource, $doi): ?Resource {
                $current = Resource::query()->lockForUpdate()->findOrFail($resource->id);
                if ($current->doi !== $doi) {
                    return null; // Retry before mutating, after releasing the row lock.
                }

                return $this->releaseLocked($current);
            });
            // Always acquire DOI before row locks, including when registration
            // has assigned an identifier since route binding or this lookup.
            $current = $doi === null || $doi === '' ? $release()
                : DataCiteDoiWriteLockService::run($doi, $testMode, function () use ($doi, $release, $testMode): ?Resource {
                    $current = $release();
                    if ($current !== null && $current->wasChanged('access_level') && $current->data_embargo_sync_pending) {
                        DataCiteDoiWriteLockService::beginWrite($doi, $testMode);
                    }

                    return $current;
                });
            if ($current !== null) {
                return $current;
            }
        }

        throw ValidationException::withMessages(['embargo' => 'The resource DOI changed while releasing data. Reload and try again.']);
    }

    /** The caller holds the resource row and the matching DOI lock, if any. */
    private function releaseLocked(Resource $current): Resource
    {
        $page = $current->landingPage()->lockForUpdate()->first();
        $current->setRelation('landingPage', $page);
        $policy = app(EmbargoService::class);

        if ($page?->is_tombstone || $current->embargo_registration_started_at !== null) {
            throw ValidationException::withMessages(['embargo' => 'Resolve the tombstone or pending DataCite registration before releasing data.']);
        }
        if (! $policy->isEmbargoed($current)) {
            return $current;
        }
        if (! $policy->isDue($current)) {
            throw ValidationException::withMessages(['embargo' => 'Data can only be released on or after one valid Available day.']);
        }
        if ($page?->isExternal()) {
            throw ValidationException::withMessages(['embargo' => 'Data embargoes require an internal landing page.']);
        }

        $current->access_level = AccessLevel::OPEN;
        $current->data_embargo_sync_pending = $current->doi !== null && $current->doi !== '' && $page?->is_published === true;
        $current->save();

        return $current;
    }

    /** Update only access metadata of a verified existing remote record. */
    public function synchronize(Resource $resource, DataCiteMemberApiClient $client): void
    {
        if (! $resource->data_embargo_sync_pending || ! $resource->doi) {
            return;
        }
        $doi = $resource->doi;
        $snapshot = DataCiteDoiWriteLockService::run($doi, $client->isTestMode(), function () use ($resource, $client, $doi): ?array {
            $current = $resource->fresh();
            if ($current === null || ! $current->data_embargo_sync_pending) {
                return null;
            }
            if ($current->doi !== $doi || $current->landingPage?->is_tombstone || ! $current->landingPage?->is_published) {
                throw new \RuntimeException('The resource publication changed. Review it before synchronizing access metadata.');
            }

            return [$current, DataCiteDoiWriteLockService::observeWrite($doi, $client->isTestMode())];
        });
        if ($snapshot === null) {
            return;
        }
        [$current, $revision] = $snapshot;

        // Both the verification GET and all rate-limit waits run without the
        // DOI lock. The transport rechecks our revision on every PUT attempt.
        $remote = $client->getDoi($doi);
        $remote->throw();
        if (strcasecmp((string) $remote->json('data.id'), $doi) !== 0
            || ! in_array($remote->json('data.attributes.state'), ['registered', 'findable'], true)) {
            throw new \RuntimeException('DataCite registration could not be verified; no remote write was sent.');
        }
        $attributes = (new DataCiteJsonExporter)->export($current)['data']['attributes'];
        $writtenRevision = null;
        $response = $client->updateDoi($doi, ['data' => [
            'type' => 'dois',
            'id' => $doi,
            'attributes' => ['rightsList' => $attributes['rightsList']],
        ]], expectedWriteRevision: $revision, writtenRevision: $writtenRevision,
            beforeWrite: fn () => $this->assertCurrentResource($current));
        $response->throw();
        if ($writtenRevision === null) {
            throw new \RuntimeException('The DataCite access update could not be confirmed.');
        }
        // Editor writes use the same lock and invalidate this revision. A
        // superseded release stays pending; its corrective job can send
        // Embargoed rights as well as Open rights for the latest local state.
        DataCiteDoiWriteLockService::run($doi, $client->isTestMode(), function () use ($current, $doi, $client, $writtenRevision): void {
            DataCiteDoiWriteLockService::assertCurrentWrite($doi, $client->isTestMode(), $writtenRevision);
            $this->assertCurrentResource($current);
            DB::transaction(function () use ($current): void {
                $latest = Resource::query()->lockForUpdate()->findOrFail($current->id);
                $latest->data_embargo_sync_pending = false;
                $latest->save();
            });
        });
    }

    /** Called under the DOI lock, immediately before a PUT or acknowledgement. */
    private function assertCurrentResource(Resource $snapshot): void
    {
        $latest = $snapshot->fresh(['dates.dateType', 'landingPage']);
        $policy = app(EmbargoService::class);
        if ($latest === null || ! $latest->data_embargo_sync_pending || $latest->doi !== $snapshot->doi
            || $latest->landingPage?->is_tombstone || ! $latest->landingPage?->is_published
            || $latest->access_level !== $snapshot->access_level
            || $policy->availableDate($latest) !== $policy->availableDate($snapshot)) {
            throw new DataCiteWriteSupersededException;
        }
    }
}
