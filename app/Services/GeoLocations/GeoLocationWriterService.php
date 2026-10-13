<?php

declare(strict_types=1);

namespace App\Services\GeoLocations;

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhDatestampService;
use App\Support\GeoLocations\GeoLocationWriteResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Serializes application writes per resource without cleaning historical rows. */
final readonly class GeoLocationWriterService
{
    public function __construct(private GeoLocationIdentityService $identity) {}

    public function save(Resource $resource, GeoLocation $location): GeoLocationWriteResult
    {
        $attributes = [];
        foreach (array_keys($location->getDirty()) as $field) {
            $attributes[$field] = $location->getAttribute($field);
        }

        return $this->write($resource, $attributes, $location);
    }

    /** @param array<string, mixed> $attributes */
    public function write(Resource $resource, array $attributes, ?GeoLocation $existing = null): GeoLocationWriteResult
    {
        return $resource->getConnection()->transaction(function () use ($resource, $attributes, $existing): GeoLocationWriteResult {
            $resource->newQuery()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $locations = $resource->geoLocations()->lockForUpdate()->get();
            $current = $existing === null ? null : $locations->firstWhere('id', $existing->id);
            if ($existing !== null && $current === null) {
                throw ValidationException::withMessages(['spatialTemporalCoverages' => 'The location no longer belongs to this resource. Reload before saving.']);
            }

            $candidate = $current === null ? new GeoLocation : clone $current;
            $candidate->setConnection($resource->getConnectionName());
            $candidate->fill($attributes);
            $candidate->resource_id = $resource->id;

            // Unchanged historical duplicates are explicitly allowed to survive.
            if ($current === null || ! $this->identity->equal($current, $candidate)) {
                foreach ($locations as $location) {
                    if ($location->id !== $current?->id && $this->identity->equal($location, $candidate)) {
                        Log::info('Identical incoming GeoLocation was merged.', [
                            'resource_id' => $resource->id,
                            'retained_geo_location_id' => $location->id,
                        ]);

                        return new GeoLocationWriteResult($location, true);
                    }
                }
            }

            if (! $candidate->exists || $candidate->isDirty()) {
                $candidate->save();
                $this->metadataChanged($resource);
            }

            return new GeoLocationWriteResult($candidate, false);
        });
    }

    public function metadataChanged(Resource $resource): void
    {
        app(OaiPmhDatestampService::class)->touchResource($resource->id);
        $resource->getConnection()->afterCommit(function () use ($resource): void {
            $landingPageId = $resource->landingPage()->value('id');
            if (is_numeric($landingPageId)) {
                app(LandingPageRenderDataCacheService::class)->forgetById((int) $landingPageId);
            }
        });
    }
}
