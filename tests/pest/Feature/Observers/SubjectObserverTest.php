<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Models\Subject;
use App\Observers\SubjectObserver;
use App\Services\PortalCacheInvalidationService;

covers(SubjectObserver::class);

beforeEach(function () {
    $this->cacheInvalidationService = Mockery::mock(PortalCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->observer = new SubjectObserver($this->cacheInvalidationService);
});

describe('saved', function () {
    it('schedules scoped portal invalidation for the owning resource', function () {
        $subject = new Subject(['resource_id' => 42]);

        $this->cacheInvalidationService->shouldReceive('scheduleForResourceId')->once()->with(42, [
            PortalCacheArea::PAGE,
            PortalCacheArea::COUNT,
            PortalCacheArea::KEYWORDS,
            PortalCacheArea::IGSN_FACETS,
            PortalCacheArea::MAP_PAYLOAD,
            PortalCacheArea::MAP_EXTENT,
        ]);

        $this->observer->saved($subject);
    });
});

describe('deleted', function () {
    it('schedules scoped portal invalidation for the owning resource', function () {
        $subject = new Subject(['resource_id' => 42]);

        $this->cacheInvalidationService->shouldReceive('scheduleForResourceId')->once()->with(42, [
            PortalCacheArea::PAGE,
            PortalCacheArea::COUNT,
            PortalCacheArea::KEYWORDS,
            PortalCacheArea::IGSN_FACETS,
            PortalCacheArea::MAP_PAYLOAD,
            PortalCacheArea::MAP_EXTENT,
        ]);

        $this->observer->deleted($subject);
    });
});
