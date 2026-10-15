<?php

declare(strict_types=1);

namespace App\Services\GeoLocations;

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhDatestampService;
use App\Support\GeoLocations\GeoLocationWriteResult;

/** Serializes application writes per resource without cleaning historical rows. */
final readonly class GeoLocationWriterService
{
    public function __construct(private GeoLocationIdentityService $identity) {}

    public function save(Resource $resource, GeoLocation $location): GeoLocationWriteResult
    {
        return $this->batch($resource, fn (GeoLocationWriteSessionService $session): GeoLocationWriteResult => $session->save($location));
    }

    /** @param array<string, mixed> $attributes */
    public function write(Resource $resource, array $attributes, ?GeoLocation $existing = null): GeoLocationWriteResult
    {
        return $this->batch($resource, fn (GeoLocationWriteSessionService $session): GeoLocationWriteResult => $session->write($attributes, $existing));
    }

    /**
     * @template TResult
     *
     * @param  callable(GeoLocationWriteSessionService): TResult  $callback
     * @return TResult
     */
    public function batch(Resource $resource, callable $callback): mixed
    {
        return $resource->getConnection()->transaction(function () use ($resource, $callback): mixed {
            $resource->newQuery()->whereKey($resource->id)->lockForUpdate()->firstOrFail();
            $session = new GeoLocationWriteSessionService($resource, $this->identity, $resource->geoLocations()->lockForUpdate()->get());
            $result = $callback($session);
            if ($session->hasChanges()) {
                $this->metadataChanged($resource);
            }

            return $result;
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
