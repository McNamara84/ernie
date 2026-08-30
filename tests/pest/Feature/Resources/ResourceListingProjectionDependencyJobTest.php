<?php

declare(strict_types=1);

use App\Jobs\RefreshResourceListingProjectionsForDependencyJob;
use App\Models\Datacenter;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceListingProjection;
use App\Models\ResourceType;
use App\Models\Right;
use App\Models\User;
use App\Observers\ResourceListingProjectionDependencyObserver;
use App\Services\ListingCountService;
use App\Services\ResourceCacheService;
use App\Services\Resources\ResourceFilterOptionsCacheInvalidationService;
use App\Services\Resources\ResourceListingProjectionRefreshService;
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
