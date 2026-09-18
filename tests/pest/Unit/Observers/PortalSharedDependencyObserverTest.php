<?php

declare(strict_types=1);

use App\Enums\PortalCacheArea;
use App\Enums\PortalScope;
use App\Models\Institution;
use App\Models\LandingPage;
use App\Models\LandingPageDomain;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
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

it('defers creator party invalidation until the listing projection refreshes', function (string $creatorClass): void {
    /** @var class-string<Person|Institution> $creatorClass */
    $creator = $creatorClass::factory()->create();
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create());
    LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]));
    ResourceCreator::withoutEvents(fn (): ResourceCreator => ResourceCreator::factory()->create([
        'resource_id' => $resource->id,
        'creatorable_type' => $creatorClass,
        'creatorable_id' => $creator->getKey(),
    ]));
    $updatedName = $creator instanceof Person
        ? ['family_name' => 'Updated family name']
        : ['name' => 'Updated institution name'];
    $creator::withoutEvents(fn (): bool => $creator->update($updatedName));
    $creator->wasRecentlyCreated = false;

    $this->invalidation->shouldNotReceive('scopeForResourceTypeId');
    $this->invalidation->shouldNotReceive('schedule');

    $this->observer->saved($creator);
})->with([Person::class, Institution::class]);

it('defers contributor-only party invalidation until the listing projection refreshes', function (string $contributorClass): void {
    /** @var class-string<Person|Institution> $contributorClass */
    $contributor = $contributorClass::factory()->create();
    $resource = Resource::withoutEvents(fn (): Resource => Resource::factory()->create());
    LandingPage::withoutEvents(fn (): LandingPage => LandingPage::factory()->published()->create([
        'resource_id' => $resource->id,
    ]));
    ResourceContributor::withoutEvents(fn (): ResourceContributor => ResourceContributor::factory()->create([
        'resource_id' => $resource->id,
        'contributorable_type' => $contributorClass,
        'contributorable_id' => $contributor->getKey(),
    ]));
    $updatedName = $contributor instanceof Person
        ? ['family_name' => 'Updated contributor name']
        : ['name' => 'Updated contributor institution'];
    $contributor::withoutEvents(fn (): bool => $contributor->update($updatedName));
    $contributor->wasRecentlyCreated = false;

    $this->invalidation->shouldNotReceive('scopeForResourceTypeId');
    $this->invalidation->shouldNotReceive('schedule');

    $this->observer->saved($contributor);
})->with([Person::class, Institution::class]);

it('defers party deletion invalidation until the listing projection refreshes', function (string $partyClass): void {
    /** @var class-string<Person|Institution> $partyClass */
    $party = $partyClass::factory()->create();

    $this->invalidation->shouldNotReceive('scopeForResourceTypeId');
    $this->invalidation->shouldNotReceive('schedule');

    $this->observer->deleted($party);
})->with([Person::class, Institution::class]);
