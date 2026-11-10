<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AccessLevel;
use App\Models\Resource;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DataEmbargoReleaseService
{
    public function release(Resource $resource): Resource
    {
        return DB::transaction(function () use ($resource): Resource {
            $current = Resource::query()->lockForUpdate()->findOrFail($resource->id);
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
            $current->data_embargo_sync_pending = $current->doi !== null && $page?->is_published === true;
            $current->save();

            return $current;
        });
    }

    /** Update only access metadata of a verified existing remote record. */
    public function synchronize(Resource $resource, DataCiteMemberApiClient $client): void
    {
        if (! $resource->data_embargo_sync_pending || ! $resource->doi) {
            return;
        }
        DataCiteDoiWriteLockService::run($resource->doi, $client->isTestMode(), function () use ($resource, $client): void {
            $current = $resource->fresh();
            if ($current === null || ! $current->data_embargo_sync_pending || app(EmbargoService::class)->isEmbargoed($current)) {
                return;
            }
            $doi = (string) $current->doi;
            $remote = $client->getDoi($doi);
            $remote->throw();
            if (strcasecmp((string) $remote->json('data.id'), $doi) !== 0
                || ! in_array($remote->json('data.attributes.state'), ['registered', 'findable'], true)) {
                throw new \RuntimeException('Data is released locally. DataCite registration could not be verified; no remote write was sent.');
            }
            $attributes = (new DataCiteJsonExporter)->export($current)['data']['attributes'];
            $latest = $current->fresh();
            if ($latest === null || app(EmbargoService::class)->isEmbargoed($latest) || $latest->landingPage?->is_tombstone) {
                throw new \RuntimeException('The resource changed before the DataCite access update. Retry after reviewing it.');
            }
            $response = $client->updateDoi($doi, ['data' => [
                'type' => 'dois',
                'id' => $doi,
                'attributes' => ['rightsList' => $attributes['rightsList']],
            ]]);
            $response->throw();
            // Do not acknowledge a release superseded by an editor's new embargo.
            DB::transaction(function () use ($current): void {
                $latest = Resource::query()->lockForUpdate()->findOrFail($current->id);
                if ($latest->updated_at !== null && $current->updated_at !== null && $latest->updated_at->equalTo($current->updated_at) && ! app(EmbargoService::class)->isEmbargoed($latest)) {
                    $latest->data_embargo_sync_pending = false;
                    $latest->save();
                }
            });
        });
    }
}
