<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\IgsnMetadata;
use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Observers\ResourceObserver;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Services\PortalCacheInvalidationService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

covers(ResourceObserver::class);

beforeEach(function () {
    $this->cacheService = Mockery::mock(ResourceCacheService::class); // @phpstan-ignore variable.undefined
    $this->cacheInvalidationService = Mockery::mock(PortalCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->oaiPmhSetService = Mockery::mock(OaiPmhSetService::class); // @phpstan-ignore variable.undefined
    $this->landingPageRenderDataCache = Mockery::mock(LandingPageRenderDataCacheService::class); // @phpstan-ignore variable.undefined
    $this->observer = new ResourceObserver(
        $this->cacheService,
        $this->oaiPmhSetService,
        $this->landingPageRenderDataCache,
        $this->cacheInvalidationService,
    );
});

// =========================================================================
// created()
// =========================================================================

describe('created', function () {
    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldNotReceive('schedule');

        $this->observer->created($resource);
    });
});

// =========================================================================
// updated()
// =========================================================================

describe('updated', function () {
    it('invalidates specific resource cache', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateResourceCache')
            ->once()
            ->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);

        $this->observer->updated($resource);
    });

    it('invalidates landing page render data when an associated resource changes', function () {
        $resource = Resource::factory()->create();
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
        ]);
        $resource->setRelation('landingPage', $landingPage);

        $this->cacheService->shouldReceive('invalidateResourceCache')
            ->once()
            ->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);
        $this->landingPageRenderDataCache->shouldReceive('forget')
            ->once()
            ->with(Mockery::on(fn (LandingPage $actual): bool => $actual->is($landingPage)))
            ->andReturn(true);

        $this->observer->updated($resource);
    });

    it('invalidates the complete sample family when an IGSN resource changes', function () {
        $resource = Resource::factory()->create([
            'doi' => '10.60510/observer-child',
            'identifier_type' => 'IGSN',
        ]);
        IgsnMetadata::query()->create([
            'resource_id' => $resource->id,
            'upload_status' => IgsnMetadata::STATUS_REGISTERED,
        ]);

        $this->cacheService->shouldReceive('invalidateResourceCache')
            ->once()
            ->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);
        $this->landingPageRenderDataCache->shouldReceive('forgetForIgsnFamilies')
            ->once()
            ->with([$resource->id]);
        $this->landingPageRenderDataCache->shouldNotReceive('forget');

        $this->observer->updated($resource);
    });

    it('bumps the assessment summary version when an assessed resource changes type', function () {
        $resource = Resource::factory()->create(['resource_type_id' => null]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => 'physical-object',
        ]);

        ResourceAssessment::withoutEvents(fn (): ResourceAssessment => ResourceAssessment::query()->create([
            'resource_id' => $resource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 6.0,
            'assessed_at' => now(),
        ]));

        Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 4);

        Resource::withoutEvents(function () use ($resource, $physicalObjectType): void {
            $resource->resource_type_id = $physicalObjectType->id;
            $resource->save();
        });

        $this->cacheService->shouldReceive('invalidateResourceCache')
            ->once()
            ->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);

        $this->observer->updated($resource);

        expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(5);
    });

    it('invalidates the DOI temporal caches when a published year changes', function () {
        $resource = Resource::factory()->create(['publication_year' => 2024]);
        Resource::withoutEvents(function () use ($resource): void {
            $resource->publication_year = 2025;
            $resource->save();
        });

        $this->cacheService->shouldReceive('invalidateResourceCache')->once()->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->with($resource)->andReturn(true);
        $this->cacheInvalidationService->shouldReceive('scopeForResource')->once()->andReturn(PortalScope::DOI);
        $this->cacheInvalidationService->shouldReceive('schedule')
            ->once()
            ->with(
                [PortalScope::DOI],
                [
                    PortalCacheArea::PAGE,
                    PortalCacheArea::COUNT,
                    PortalCacheArea::MAP_PAYLOAD,
                    PortalCacheArea::MAP_EXTENT,
                    PortalCacheArea::TEMPORAL_RANGE,
                ],
            );

        $this->observer->updated($resource);
    });

    it('invalidates both portal scopes when a published resource changes type', function () {
        $resource = Resource::factory()->create(['resource_type_id' => null]);
        $physicalObjectType = ResourceType::factory()->create([
            'name' => 'Physical Object',
            'slug' => PortalScope::PHYSICAL_SAMPLE_RESOURCE_TYPE,
        ]);
        Resource::withoutEvents(function () use ($resource, $physicalObjectType): void {
            $resource->resource_type_id = $physicalObjectType->id;
            $resource->save();
        });

        $this->cacheService->shouldReceive('invalidateResourceCache')->once()->with($resource->id);
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(true);
        $this->cacheInvalidationService->shouldReceive('scopeForResource')->once()->andReturn(PortalScope::IGSN);
        $this->cacheInvalidationService->shouldReceive('scopeForResourceTypeId')->once()->andReturn(PortalScope::DOI);
        $this->cacheInvalidationService->shouldReceive('schedule')
            ->once()
            ->with([PortalScope::IGSN, PortalScope::DOI], PortalCacheArea::all());

        $this->observer->updated($resource);
    });

    it('syncs DOI to landing page when DOI changes', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/old.doi']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/old.doi',
            'is_published' => false,
        ]);

        // Update the DOI
        $resource->doi = '10.5880/new.doi';
        $resource->save();

        $landingPage->refresh();

        expect($landingPage->doi_prefix)->toBe('10.5880/new.doi');
    });

    it('does not sync DOI when DOI was not changed', function () {
        $resource = Resource::factory()->create(['doi' => '10.5880/stable.doi']);
        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'doi_prefix' => '10.5880/stable.doi',
            'is_published' => false,
        ]);

        // Update a non-DOI field
        $resource->version = '2.0';
        $resource->save();

        $landingPage->refresh();

        expect($landingPage->doi_prefix)->toBe('10.5880/stable.doi');
    });
});

// =========================================================================
// deleted()
// =========================================================================

describe('deleted', function () {
    it('deletes a managed image after a resource cascade commits', function () {
        Storage::fake('public');
        Config::set('igsn_images.disk', 'public');
        $resource = Resource::factory()->create(['doi' => '10.60510/gfso273cascade']);
        $path = 'igsn-sample-images/gfso273cascade/sample.jpg';
        IgsnMetadata::query()->create([
            'resource_id' => $resource->id,
            'sample_image_storage_path' => $path,
        ]);
        Storage::disk('public')->put($path, 'image');
        $this->landingPageRenderDataCache
            ->shouldReceive('forgetForIgsnFamilies')
            ->once()
            ->with([(int) $resource->id]);

        $startingTransactionLevel = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $this->observer->deleting($resource);
            Resource::withoutEvents(function () use ($resource): void {
                $resource->delete();
            });

            expect(IgsnMetadata::query()->where('resource_id', $resource->id)->exists())->toBeFalse();
            Storage::disk('public')->assertExists($path);
            DB::commit();
        } finally {
            while (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }
        }

        Storage::disk('public')->assertMissing($path);
    });

    it('keeps a managed IGSN sample image when the enclosing transaction rolls back', function () {
        Storage::fake('public');
        Config::set('igsn_images.disk', 'public');
        $resource = Resource::factory()->create(['doi' => '10.60510/gfso273n39']);
        IgsnMetadata::query()->create([
            'resource_id' => $resource->id,
            'sample_image_storage_path' => 'igsn-sample-images/gfso273n39/sample.jpg',
        ]);
        Storage::disk('public')->put('igsn-sample-images/gfso273n39/sample.jpg', 'image');
        $this->landingPageRenderDataCache
            ->shouldReceive('forgetForIgsnFamilies')
            ->once()
            ->with([(int) $resource->id]);

        DB::beginTransaction();
        try {
            $this->observer->deleting($resource);

            Storage::disk('public')->assertExists('igsn-sample-images/gfso273n39/sample.jpg');
        } finally {
            DB::rollBack();
        }

        Storage::disk('public')->assertExists('igsn-sample-images/gfso273n39/sample.jpg');
    });

    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);
        $this->oaiPmhSetService->shouldReceive('getSetsForResource')
            ->andReturn([]);

        $this->observer->deleted($resource);
    });

    it('bumps the assessment summary version when deleting a resource with an assessment', function () {
        $resource = Resource::factory()->create(['doi' => null]);

        ResourceAssessment::withoutEvents(fn (): ResourceAssessment => ResourceAssessment::query()->create([
            'resource_id' => $resource->id,
            'status' => ResourceAssessment::STATUS_COMPLETED,
            'total_score' => 6.0,
            'assessed_at' => now(),
        ]));

        Cache::forever(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version'), 7);

        $this->observer->deleting($resource);

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);
        $this->oaiPmhSetService->shouldNotReceive('getSetsForResource');

        $this->observer->deleted($resource);

        expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(8);
    });

    it('does not track OAI-PMH deletion for resources without DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);
        $this->oaiPmhSetService->shouldNotReceive('getSetsForResource');

        $this->observer->deleted($resource);

        expect(OaiPmhDeletedRecord::count())->toBe(0);
    });
});

// =========================================================================
// forceDeleted()
// =========================================================================

describe('forceDeleted', function () {
    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('isPublished')->once()->andReturn(false);

        $this->observer->forceDeleted($resource);
    });
});
