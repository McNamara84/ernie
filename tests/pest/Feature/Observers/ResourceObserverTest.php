<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use App\Observers\ResourceObserver;
use App\Services\KeywordSuggestionService;
use App\Services\OaiPmh\OaiPmhSetService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;

covers(ResourceObserver::class);

beforeEach(function () {
    $this->cacheService = Mockery::mock(ResourceCacheService::class); // @phpstan-ignore variable.undefined
    $this->keywordService = Mockery::mock(KeywordSuggestionService::class); // @phpstan-ignore variable.undefined
    $this->oaiPmhSetService = Mockery::mock(OaiPmhSetService::class); // @phpstan-ignore variable.undefined
    $this->observer = new ResourceObserver($this->cacheService, $this->keywordService, $this->oaiPmhSetService);
});

// =========================================================================
// created()
// =========================================================================

describe('created', function () {
    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->keywordService->shouldReceive('invalidateCache')
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
        $this->keywordService->shouldReceive('invalidateCache')
            ->once();

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
    it('invalidates all resource caches', function () {
        $resource = Resource::factory()->create();

        $this->cacheService->shouldReceive('invalidateAllResourceCaches')
            ->once();
        $this->keywordService->shouldReceive('invalidateCache')
            ->once();
        $this->oaiPmhSetService->shouldReceive('getSetsForResource')
            ->andReturn([]);

        $this->observer->deleted($resource);
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
        $this->keywordService->shouldReceive('invalidateCache')
            ->once();

        $this->observer->forceDeleted($resource);
    });
});
