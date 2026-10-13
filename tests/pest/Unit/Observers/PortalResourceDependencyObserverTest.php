<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Models\AlternateIdentifier;
use App\Models\Description;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\IgsnMetadata;
use App\Models\RelatedIdentifier;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Models\Title;
use App\Observers\PortalResourceDependencyObserver;
use App\Services\PortalCacheInvalidationService;

covers(PortalResourceDependencyObserver::class);

beforeEach(function (): void {
    $this->invalidation = Mockery::mock(PortalCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->observer = new PortalResourceDependencyObserver($this->invalidation); // @phpstan-ignore variable.undefined
});

it('invalidates result and map caches for searchable text and identifiers', function (string $modelClass): void {
    /** @var class-string<Title|Description|AlternateIdentifier|RelatedIdentifier> $modelClass */
    $model = new $modelClass(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($model);
})->with([Title::class, Description::class, AlternateIdentifier::class, RelatedIdentifier::class]);

it('invalidates map result and extent caches when a resource party changes', function (string $partyClass): void {
    /** @var class-string<ResourceCreator|ResourceContributor> $partyClass */
    $party = new $partyClass(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($party);
})->with([ResourceCreator::class, ResourceContributor::class]);

it('invalidates map caches for geolocation changes', function (): void {
    $location = new GeoLocation(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->deleted($location);
});

it('invalidates the temporal range for resource-date changes', function (): void {
    $date = new ResourceDate(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::TEMPORAL_RANGE,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($date);
});

it('invalidates IGSN result, count and map caches for filter metadata', function (): void {
    $classification = new IgsnClassification(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($classification);
});

it('invalidates IGSN map presentation when material changes', function (): void {
    $metadata = new IgsnMetadata(['resource_id' => 42, 'material' => 'Rock']);
    $metadata->syncOriginal();
    $metadata->material = 'Soil';
    $metadata->syncChanges();

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::IGSN_FACETS,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($metadata);
});

it('does not invalidate public caches for unrelated IGSN metadata updates', function (): void {
    $metadata = new IgsnMetadata(['resource_id' => 42, 'material' => 'Rock', 'sample_access' => 'open']);
    $metadata->syncOriginal();
    $metadata->sample_access = 'closed';
    $metadata->syncChanges();

    $this->invalidation->shouldNotReceive('scheduleForResourceId');

    $this->observer->saved($metadata);
});

it('ignores models without a numeric resource id', function (): void {
    $this->invalidation->shouldNotReceive('scheduleForResourceId');

    $this->observer->saved(new Title);
});
