<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\LandingPageDomain;
use App\Models\TitleType;
use App\Observers\PortalSharedDependencyObserver;
use App\Services\PortalCacheInvalidationService;

covers(PortalSharedDependencyObserver::class);

beforeEach(function (): void {
    $this->invalidation = Mockery::mock(PortalCacheInvalidationService::class); // @phpstan-ignore variable.undefined
    $this->observer = new PortalSharedDependencyObserver($this->invalidation); // @phpstan-ignore variable.undefined
});

it('invalidates page and map payload caches when a landing page domain changes', function (): void {
    $domain = new LandingPageDomain(['domain' => 'https://example.test']);
    $domain->wasRecentlyCreated = true;

    $this->invalidation->shouldReceive('schedule')->once()->with(PortalScope::cases(), [
        PortalCacheArea::PAGE,
        PortalCacheArea::MAP_PAYLOAD,
    ]);

    $this->observer->saved($domain);
});

it('invalidates map marker titles when a title type slug changes', function (): void {
    $titleType = new TitleType(['name' => 'Main title', 'slug' => 'main-title']);
    $titleType->wasRecentlyCreated = true;

    $this->invalidation->shouldReceive('schedule')->once()->with(PortalScope::cases(), [
        PortalCacheArea::PAGE,
        PortalCacheArea::COUNT,
        PortalCacheArea::MAP_PAYLOAD,
    ]);

    $this->observer->saved($titleType);
});

it('invalidates page and map payload caches when a landing page domain is deleted', function (): void {
    $domain = new LandingPageDomain(['domain' => 'https://example.test']);

    $this->invalidation->shouldReceive('schedule')->once()->with(PortalScope::cases(), [
        PortalCacheArea::PAGE,
        PortalCacheArea::MAP_PAYLOAD,
    ]);

    $this->observer->deleted($domain);
});
