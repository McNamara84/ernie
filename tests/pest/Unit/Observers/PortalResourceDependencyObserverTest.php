<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Models\GeoLocation;
use App\Models\IgsnClassification;
use App\Models\ResourceDate;
use App\Models\Title;
use App\Observers\PortalResourceDependencyObserver;
use App\Services\PortalCacheInvalidationService;

covers(PortalResourceDependencyObserver::class);

beforeEach(function (): void {
    $this->invalidation = Mockery::mock(PortalCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->observer = new PortalResourceDependencyObserver($this->invalidation); // @phpstan-ignore variable.undefined
});

it('invalidates result and count caches for result-card dependencies', function (): void {
    $title = new Title(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
    ]);

    $this->observer->saved($title);
});

it('invalidates map caches for geolocation changes', function (): void {
    $location = new GeoLocation(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
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
    ]);

    $this->observer->saved($date);
});

it('invalidates IGSN result, count and map caches for filter metadata', function (): void {
    $classification = new IgsnClassification(['resource_id' => 42]);

    $this->invalidation->shouldReceive('scheduleForResourceId')->once()->with(42, [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::MAP_PAYLOAD,
        PortalCacheArea::MAP_EXTENT,
    ]);

    $this->observer->saved($classification);
});

it('ignores models without a numeric resource id', function (): void {
    $this->invalidation->shouldNotReceive('scheduleForResourceId');

    $this->observer->saved(new Title);
});
