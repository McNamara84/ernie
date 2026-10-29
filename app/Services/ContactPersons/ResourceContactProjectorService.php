<?php

declare(strict_types=1);

namespace App\Services\ContactPersons;

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final readonly class ResourceContactProjectorService
{
    // Match the listing projector so one listing batch needs one contact read.
    private const BATCH_SIZE = 500;

    public function __construct(
        private ContactPersonIdentityService $identities,
        private ResourceCacheService $resourceCache,
        private ListingCountService $counts,
    ) {}

    /** @param iterable<int> $resourceIds */
    public function refreshMany(iterable $resourceIds): void
    {
        // Earlier migrations rebuild the listing before this table exists.
        if (! Schema::hasTable('resource_contact_points')) {
            return;
        }

        $ids = collect($resourceIds)->unique()->sort()->values();
        foreach ($ids->chunk(self::BATCH_SIZE) as $chunk) {
            DB::transaction(function () use ($chunk): void {
                // Serialize replacement and read the sources AFTER obtaining the lock.
                $resources = Resource::query()->whereKey($chunk->all())->orderBy('id')->lockForUpdate()->get(['id']);
                $resources->load(['creators.creatorable', 'contributors.contributorable', 'contributors.contributorTypes']);
                $rows = [];
                foreach ($resources as $resource) {
                    /** @var array<string, array<string, int|string|null>> $points */
                    $points = [];
                    foreach ([...$resource->creators, ...$resource->contributors] as $relation) {
                        $person = $relation instanceof ResourceCreator ? $relation->creatorable : $relation->contributorable;
                        $isContact = $relation instanceof ResourceCreator
                            ? $relation->is_contact
                            : $relation->contributorTypes->contains('slug', 'ContactPerson');
                        if (! $isContact || ! $person instanceof Person) {
                            continue;
                        }

                        $point = ['resource_id' => $resource->id, ...$this->identities->point($person, $relation)];
                        $key = $point['variant_key'];
                        // Stable representative when addresses differ only in casing.
                        if (! isset($points[$key]) || strcmp((string) $point['email'], (string) $points[$key]['email']) < 0) {
                            $points[$key] = $point;
                        }
                    }
                    array_push($rows, ...array_values($points));
                }

                DB::table('resource_contact_points')->whereIn('resource_id', $chunk->all())->delete();
                foreach (array_chunk($rows, 100) as $batch) {
                    DB::table('resource_contact_points')->insert($batch);
                }
            });
        }

        if ($ids->isNotEmpty()) {
            $this->resourceCache->invalidateAllResourceCaches();
            $this->counts->scheduleInternalInvalidationAfterCommit();
        }
    }

    public function rebuildAll(): void
    {
        Resource::query()->select('id')->chunkById(self::BATCH_SIZE, function ($resources): void {
            $this->refreshMany($resources->modelKeys());
        });
    }
}
