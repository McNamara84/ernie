<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\Datacenter;
use App\Models\Description;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\ResourceListingProjection;
use App\Models\ResourceRight;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\Title;
use App\Models\User;
use App\Observers\ResourceListingProjectionDependencyObserver;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceFilterOptionsCacheInvalidationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
use App\Services\Spdx\SpdxLicenseLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function runResourceListingProjectionDependencyJob(RefreshResourceListingProjectionsForDependencyJob $job): void
{
    $job->handle(
        app(ResourceListingProjectionRefreshService::class),
        app(ResourceCacheService::class),
        app(ListingCountService::class),
    );
}

it('invalidates only the filter-options cache when a datacenter name changes', function (): void {
    Queue::fake();
    $cacheInvalidationService = Mockery::mock(ResourceFilterOptionsCacheInvalidationService::class);
    $cacheInvalidationService->shouldReceive('scheduleAfterCommit')->once();
    $observer = new ResourceListingProjectionDependencyObserver($cacheInvalidationService);
    $datacenter = Datacenter::factory()->create(['name' => 'Original Datacenter']);

    $datacenter->updateQuietly(['name' => 'Renamed Datacenter']);
    $observer->updated($datacenter);

    $datacenter->syncChanges();
    $observer->updated($datacenter);

    Queue::assertNotPushed(RefreshResourceListingProjectionsForDependencyJob::class);
});

it('queues only projection-relevant lookup saves after commit without loading dependent resource ids', function (): void {
    $curator = User::factory()->create(['name' => 'Original Curator']);
    $resourceType = ResourceType::factory()->create(['name' => 'Original Type', 'slug' => 'original-type']);
    $right = Right::factory()->create(['name' => 'Original Right']);
    Resource::factory()->create([
        'created_by_user_id' => $curator->id,
        'resource_type_id' => $resourceType->id,
    ])->rights()->attach($right);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    Queue::fake();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    $curator->update(['name' => 'Updated Curator']);
    $resourceType->update(['name' => 'Updated Type']);
    $right->update(['name' => 'Updated Right']);

    Queue::assertPushed(RefreshResourceListingProjectionsForDependencyJob::class, 2);
    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === User::class
            && $job->dependencyId === $curator->id
            && $job->event === RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED
            && $job->afterCommit === true,
    );
    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === ResourceType::class
            && $job->dependencyId === $resourceType->id
            && $job->event === RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED
            && $job->afterCommit === true,
    );
    Queue::assertNotPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === Right::class,
    );

    expect(collect($queries)->filter(
        static fn (string $sql): bool => str_starts_with($sql, 'select')
            && (str_contains($sql, ' from "resources"') || str_contains($sql, ' from "resource_rights"')),
    ))->toBeEmpty();
});

it('queues and refreshes affected projections when a right changes SPDX scheme', function (): void {
    $right = Right::factory()->create([
        'identifier' => 'CUSTOM-SCHEME-CHANGE',
        'scheme_uri' => null,
    ]);
    $resource = Resource::factory()->create(['doi' => '10.5880/spdx-scheme-change']);
    $resource->rights()->attach($right);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->has_spdx_license)->toBeFalse();

    Queue::fake();
    $right->update(['scheme_uri' => SpdxLicenseLookup::SCHEME_URI]);

    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === Right::class
            && $job->dependencyId === $right->id
            && $job->event === RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED
            && $job->afterCommit === true,
    );

    /** @var RefreshResourceListingProjectionsForDependencyJob $job */
    $job = Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class)
        ->first(fn (RefreshResourceListingProjectionsForDependencyJob $queuedJob): bool => $queuedJob->dependencyType === Right::class);
    runResourceListingProjectionDependencyJob($job);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($resource->id)->has_spdx_license)->toBeTrue();
});

it('updates curator and resource type projection values with targeted set-based writes', function (): void {
    $curator = User::factory()->create(['name' => 'Original Curator']);
    $resourceType = ResourceType::factory()->create(['name' => 'Original Type', 'slug' => 'original-type']);
    $resource = Resource::factory()->create([
        'created_by_user_id' => $curator->id,
        'resource_type_id' => $resourceType->id,
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    $curator->updateQuietly(['name' => 'Updated Curator']);
    $resourceType->updateQuietly(['name' => 'Physical Object', 'slug' => 'physical-object']);

    runResourceListingProjectionDependencyJob(new RefreshResourceListingProjectionsForDependencyJob(
        User::class,
        $curator->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ));
    runResourceListingProjectionDependencyJob(new RefreshResourceListingProjectionsForDependencyJob(
        ResourceType::class,
        $resourceType->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ));

    $projection = ResourceListingProjection::query()->findOrFail($resource->id);
    expect($projection->curator_name)->toBe('Updated Curator')
        ->and($projection->resource_type_sort)->toBe('Physical Object')
        ->and($projection->resource_type_slug)->toBe('physical-object')
        ->and($projection->is_igsn)->toBeTrue();
});

it('refreshes only resources that referenced a deleted catalog right', function (): void {
    $right = Right::factory()->create();
    $affected = Resource::factory()->create(['access_level' => AccessLevel::OPEN]);
    Title::factory()->create(['resource_id' => $affected->id]);
    ResourceCreator::factory()->create(['resource_id' => $affected->id]);
    Description::factory()->abstract()->create(['resource_id' => $affected->id]);
    $affected->rights()->attach($right);

    $unrelated = Resource::factory()->create();
    ResourceRight::query()->create([
        'resource_id' => $unrelated->id,
        'rights_id' => null,
        'rights_text' => 'Unresolved imported right',
    ]);
    app(ResourceListingProjectionRefreshService::class)->flushPending();
    expect(ResourceListingProjection::query()->findOrFail($affected->id)->workflow_status)->toBe('curation');

    ResourceListingProjection::query()->whereKey($unrelated->id)->update([
        'main_title' => 'Unrelated projection sentinel',
    ]);

    Queue::fake();
    $right->delete();

    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        1,
    );
    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === Right::class
            && $job->dependencyId === $right->id
            && $job->event === RefreshResourceListingProjectionsForDependencyJob::EVENT_DELETED
            && $job->affectedResourceIds === [$affected->id]
            && $job->afterCommit === true,
    );

    /** @var RefreshResourceListingProjectionsForDependencyJob $job */
    $job = Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class)->first();
    runResourceListingProjectionDependencyJob($job);
    app(ResourceListingProjectionRefreshService::class)->flushPending();

    expect(ResourceListingProjection::query()->findOrFail($affected->id)->workflow_status)->toBe('draft')
        ->and(ResourceListingProjection::query()->findOrFail($unrelated->id)->main_title)
        ->toBe('Unrelated projection sentinel');
});

it('chunks resources affected by a deleted catalog right into bounded jobs', function (): void {
    $right = Right::factory()->create();
    $now = now();

    collect(range(1, RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE + 1))
        ->chunk(100)
        ->each(fn ($chunk) => DB::table('resources')->insert($chunk->map(fn (): array => [
            'created_at' => $now,
            'updated_at' => $now,
        ])->all()));

    $resourceIds = Resource::query()->orderBy('id')->pluck('id');
    $resourceIds->chunk(100)->each(fn ($chunk) => DB::table('resource_rights')->insert(
        $chunk->map(fn (int $resourceId): array => [
            'resource_id' => $resourceId,
            'rights_id' => $right->id,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all(),
    ));

    Queue::fake();
    $right->delete();

    $jobs = Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class)
        ->filter(fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->dependencyType === Right::class)
        ->values();

    expect($jobs)->toHaveCount(2)
        ->and($jobs->map(fn (RefreshResourceListingProjectionsForDependencyJob $job): int => count($job->affectedResourceIds ?? []))->all())
        ->toBe([RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE, 1])
        ->and($jobs->flatMap(fn (RefreshResourceListingProjectionsForDependencyJob $job): array => $job->affectedResourceIds ?? [])->all())
        ->toBe($resourceIds->all());
});

it('refreshes at most 500 dependent resources per job and chains the remaining cursor', function (): void {
    $person = Person::factory()->create(['family_name' => 'Chunked Creator']);
    $now = now();

    collect(range(1, RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE + 1))
        ->chunk(100)
        ->each(fn ($chunk) => DB::table('resources')->insert($chunk->map(fn (): array => [
            'created_at' => $now,
            'updated_at' => $now,
        ])->all()));

    $resourceIds = Resource::query()->orderBy('id')->pluck('id');
    $resourceIds->chunk(100)->each(fn ($chunk) => DB::table('resource_creators')->insert(
        $chunk->map(fn (int $resourceId): array => [
            'resource_id' => $resourceId,
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
            'position' => 0,
            'is_contact' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all(),
    ));

    Queue::fake();
    runResourceListingProjectionDependencyJob(new RefreshResourceListingProjectionsForDependencyJob(
        Person::class,
        $person->id,
        RefreshResourceListingProjectionsForDependencyJob::EVENT_UPDATED,
    ));

    $firstBatchLastId = (int) $resourceIds->get(RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE - 1);
    expect(ResourceListingProjection::query()->count())
        ->toBe(RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE);
    Queue::assertPushed(
        RefreshResourceListingProjectionsForDependencyJob::class,
        fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->afterResourceId === $firstBatchLastId
            && $job->afterCommit === true,
    );

    /** @var RefreshResourceListingProjectionsForDependencyJob $nextJob */
    $nextJob = Queue::pushed(RefreshResourceListingProjectionsForDependencyJob::class)
        ->first(fn (RefreshResourceListingProjectionsForDependencyJob $job): bool => $job->afterResourceId === $firstBatchLastId);
    runResourceListingProjectionDependencyJob($nextJob);

    expect(ResourceListingProjection::query()->count())
        ->toBe(RefreshResourceListingProjectionsForDependencyJob::BATCH_SIZE + 1);
});
