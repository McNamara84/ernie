<?php

declare(strict_types=1);

namespace App\Services\GeoLocations;

use App\Models\GeoLocation;
use App\Models\Resource;
use App\Support\GeoLocations\GeoLocationWriteResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** An identity index scoped to one locked resource transaction. */
final class GeoLocationWriteSessionService
{
    /** @var Collection<int, GeoLocation> */
    private Collection $locations;

    /** @var array<string, array<int, GeoLocation>> */
    private array $index = [];

    /** @var array<int, string> */
    private array $fingerprints = [];

    private bool $changed = false;

    /** @param Collection<int, GeoLocation> $locations */
    public function __construct(private readonly Resource $resource, private readonly GeoLocationIdentityService $identity, Collection $locations)
    {
        $this->locations = $locations->keyBy('id');
        foreach ($locations as $location) {
            $this->indexLocation($location);
        }
    }

    /** @return Collection<int, GeoLocation> */
    public function locations(): Collection
    {
        return $this->locations->values();
    }

    public function hasChanges(): bool
    {
        return $this->changed;
    }

    public function save(GeoLocation $location): GeoLocationWriteResult
    {
        $attributes = [];
        foreach (array_keys($location->getDirty()) as $field) {
            $attributes[$field] = $location->getAttribute($field);
        }

        return $this->write($attributes, $location);
    }

    /** @param array<string, mixed> $attributes */
    public function write(array $attributes, ?GeoLocation $existing = null): GeoLocationWriteResult
    {
        $current = $existing === null ? null : $this->locations->get($existing->id);
        if ($existing !== null && $current === null) {
            throw ValidationException::withMessages(['spatialTemporalCoverages' => 'The location no longer belongs to this resource. Reload before saving.']);
        }
        $candidate = $current === null ? new GeoLocation : clone $current;
        $candidate->setConnection($this->resource->getConnectionName());
        $candidate->fill($attributes);
        $candidate->resource_id = $this->resource->id;
        $fingerprint = $this->identity->fingerprint($candidate);

        // Unchanged historical duplicates are explicitly allowed to survive.
        if ($current === null || ! $this->identity->equal($current, $candidate)) {
            foreach ($this->index[$fingerprint] ?? [] as $location) {
                // Verify full equality as well, so hash collisions cannot merge rows.
                if ($location->id !== $current?->id && $this->identity->equal($location, $candidate)) {
                    Log::info('Identical incoming GeoLocation was merged.', [
                        'resource_id' => $this->resource->id,
                        'retained_geo_location_id' => $location->id,
                    ]);

                    return new GeoLocationWriteResult($location, true);
                }
            }
        }

        if (! $candidate->exists || $candidate->isDirty()) {
            $candidate->save();
            $this->changed = true;
        }
        if ($current !== null && $this->fingerprints[$current->id] !== $fingerprint) {
            $this->removeFromIndex($current);
        }
        $this->locations->put($candidate->id, $candidate);
        $this->indexLocation($candidate);

        return new GeoLocationWriteResult($candidate, false);
    }

    public function delete(GeoLocation $location): void
    {
        $current = $this->locations->get($location->id);
        if ($current === null) {
            throw ValidationException::withMessages(['spatialTemporalCoverages' => 'The location no longer belongs to this resource. Reload before saving.']);
        }
        $current->delete();
        $this->removeFromIndex($current);
        $this->locations->forget($current->id);
        $this->changed = true;
    }

    private function indexLocation(GeoLocation $location): void
    {
        $fingerprint = $this->identity->fingerprint($location);
        $this->index[$fingerprint][$location->id] = $location;
        $this->fingerprints[$location->id] = $fingerprint;
    }

    private function removeFromIndex(GeoLocation $location): void
    {
        $fingerprint = $this->fingerprints[$location->id];
        unset($this->index[$fingerprint][$location->id], $this->fingerprints[$location->id]);
        if ($this->index[$fingerprint] === []) {
            unset($this->index[$fingerprint]);
        }
    }
}
