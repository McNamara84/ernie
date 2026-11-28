<?php

declare(strict_types=1);

use App\Models\Resource;
use App\Models\ResourceCurationEvent;
use App\Models\ResourceType;
use App\Services\Curation\CurationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use Illuminate\Support\Facades\DB;

it('keeps unchanged projection batches bounded at five hundred tracked resources', function (): void {
    $prototype = Resource::factory()->create(['doi' => null]);
    app(CurationService::class)->start($prototype, null, 'backfill');
    $snapshot = ResourceCurationEvent::where('resource_id', $prototype->id)->firstOrFail()->payload['snapshot'];
    // JSON objects have no key order; simulate a database normalizing their order.
    $snapshot = ['status' => $snapshot['status'], 'automatic' => array_reverse($snapshot['automatic'], true)];
    $resources = [];
    $states = [];
    $events = [];
    $now = now();
    $ids = [$prototype->id];
    for ($i = 1; $i < 500; $i++) {
        $id = $prototype->id + $i;
        $ids[] = $id;
        $resources[] = [...$prototype->getAttributes(), 'id' => $id];
        $states[] = ['resource_id' => $id, 'start_source' => 'backfill', 'started_at' => $now, 'last_activity_at' => $now];
        $events[] = ['resource_id' => $id, 'type' => 'curation.started', 'actor_name' => 'ERNIE', 'created_at' => $now,
            'payload' => json_encode(['snapshot' => $snapshot], JSON_THROW_ON_ERROR)];
    }
    DB::table('resources')->insert($resources);
    DB::table('resource_curation_states')->insert($states);
    DB::table('resource_curation_events')->insert($events);
    $scheduler = app(ResourceListingProjectionRefreshService::class);
    $scheduler->flushPending();
    $this->travel(1)->hour();
    $measure = function (array $batch) use ($scheduler): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $scheduler->scheduleMany($batch);
            $scheduler->flushPending();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    };
    $small = $measure(array_slice($ids, 0, 5));
    $large = $measure($ids);
    expect($large)->toBeLessThanOrEqual($small + 2)->toBeLessThan(80)
        ->and(ResourceCurationEvent::count())->toBe(500)
        ->and(DB::table('resource_curation_states')->where('last_activity_at', '>', $now)->count())->toBe(0)
        ->and(DB::table('resource_listing_projections')->whereIn('resource_id', $ids)->count())->toBe(500);
});

it('reconciles changed resources once and skips missing untracked and IGSN records', function (): void {
    $service = app(CurationService::class);
    $tracked = Resource::factory()->create(['doi' => null]);
    $converted = Resource::factory()->create(['doi' => null]);
    $untracked = Resource::factory()->create(['doi' => null]);
    $service->start($tracked, null, 'backfill');
    $service->start($converted, null, 'backfill');
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    $type = ResourceType::firstOrCreate(['slug' => 'physical-object'], ['name' => 'Physical Object']);
    DB::table('resources')->where('id', $converted->id)->update(['resource_type_id' => $type->id]);
    DB::table('resources')->where('id', $tracked->id)->update(['doi' => '10.1234/batch-reconciled']);

    $service->reconcileMany([$tracked->id, $tracked->id, $converted->id, $untracked->id, 999999, 0, -1]);
    $service->reconcileMany([$tracked->id]);

    expect(ResourceCurationEvent::where('type', 'resource.changed')->count())->toBe(1)
        ->and($tracked->checklistItems()->where('item_key', 'doi_assigned')->value('state'))->toBe('done')
        ->and($untracked->curationState()->exists())->toBeFalse()
        ->and(ResourceCurationEvent::where('resource_id', $converted->id)->count())->toBe(1);
});

it('rolls back a reconciled batch with the surrounding mutation', function (): void {
    $service = app(CurationService::class);
    $resources = Resource::factory()->count(2)->create(['doi' => null]);
    foreach ($resources as $resource) {
        $service->start($resource, null, 'backfill');
    }
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    DB::beginTransaction();
    try {
        foreach ($resources as $resource) {
            DB::table('resources')->where('id', $resource->id)->update(['doi' => '10.1234/rollback-'.$resource->id]);
        }
        $service->reconcileMany($resources->modelKeys());
        expect(ResourceCurationEvent::where('type', 'resource.changed')->count())->toBe(2);
    } finally {
        DB::rollBack();
    }
    expect(ResourceCurationEvent::where('type', 'resource.changed')->count())->toBe(0);
    foreach ($resources as $resource) {
        expect($resource->fresh()->doi)->toBeNull()
            ->and($resource->checklistItems()->where('item_key', 'doi_assigned')->value('state'))->toBe('open');
    }
});
