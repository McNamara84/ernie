<?php

declare(strict_types=1);

use App\Models\Subject;
use App\Observers\SubjectObserver;
use App\Services\PortalKeywordCacheInvalidationService;

covers(SubjectObserver::class);

beforeEach(function () {
    $this->cacheInvalidationService = Mockery::mock(PortalKeywordCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->observer = new SubjectObserver($this->cacheInvalidationService);
});

describe('saved', function () {
    it('schedules portal keyword cache invalidation after commit', function () {
        $subject = new Subject();

        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')->once();

        $this->observer->saved($subject);
    });
});

describe('deleted', function () {
    it('schedules portal keyword cache invalidation after commit', function () {
        $subject = new Subject();

        $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')->once();

        $this->observer->deleted($subject);
    });
});