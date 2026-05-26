<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\OaiPmhDeletedRecord;
use App\Models\Resource;
use App\Models\ResourceAssessment;
use App\Models\ResourceType;
use App\Observers\ResourceObserver;
use App\Enums\CacheKey;
use App\Services\BotProtection\LandingPageRenderDataCacheService;
use App\Services\BotProtection\PortalPageCacheService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Services\PortalKeywordCacheInvalidationService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;

covers(ResourceObserver::class);

beforeEach(function () {
    $this->cacheService = Mockery::mock(ResourceCacheService::class); // @phpstan-ignore variable.undefined
    $this->cacheInvalidationService = Mockery::mock(PortalKeywordCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->oaiPmhSetService = Mockery::mock(OaiPmhSetService::class); // @phpstan-ignore variable.undefined
    $this->landingPageRenderDataCache = Mockery::mock(LandingPageRenderDataCacheService::class); // @phpstan-ignore variable.undefined
    $this->portalPageCache = Mockery::mock(PortalPageCacheService::class); // @phpstan-ignore variable.undefined
    $this->observer = new ResourceObserver(
        $this->cacheService,
        $this->cacheInvalidationService,
        $this->oaiPmhSetService,
        $this->landingPageRenderDataCache,
        $this->portalPageCache,
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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->portalPageCache->shouldReceive('flush')
            ->once();

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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->portalPageCache->shouldReceive('flush')
            ->once();

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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->landingPageRenderDataCache->shouldReceive('forget')
            ->once()
            ->with(Mockery::on(fn (LandingPage $actual): bool => $actual->is($landingPage)))
            ->andReturn(true);
        $this->portalPageCache->shouldReceive('flush')
            ->once();

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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->portalPageCache->shouldReceive('flush')
            ->once();

        $this->observer->updated($resource);

        expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(5);
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
    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->oaiPmhSetService->shouldReceive('getSetsForResource')
            ->andReturn([]);
        $this->portalPageCache->shouldReceive('flush')
            ->once();

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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->oaiPmhSetService->shouldNotReceive('getSetsForResource');
        $this->portalPageCache->shouldReceive('flush')
            ->once();

        $this->observer->deleted($resource);

        expect((int) Cache::get(CacheKey::ASSESSMENT_AVERAGE_SUMMARY->key('version')))->toBe(8);
    });

    it('does not track OAI-PMH deletion for resources without DOI', function () {
        $resource = Resource::factory()->create(['doi' => null]);

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->oaiPmhSetService->shouldNotReceive('getSetsForResource');
        $this->portalPageCache->shouldReceive('flush')
            ->once();

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
        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')
            ->once();
        $this->portalPageCache->shouldReceive('flush')
            ->once();

        $this->observer->forceDeleted($resource);
    });
});
