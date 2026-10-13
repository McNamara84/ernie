<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Datacenter;
use App\Models\DateType;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\ResourceType;
use App\Services\DateType\DateTypeUsageService;
use App\Services\ResourceCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

covers(DateTypeUsageService::class);

beforeEach(function () {
    Cache::flush();
});

it('counts every date entry across resource types, datacenters and unpublished imports', function () {
    $created = DateType::factory()->create(['slug' => 'created']);
    $collected = DateType::factory()->create(['slug' => 'collected', 'is_active' => false]);
    $unused = DateType::factory()->create(['slug' => 'unused']);
    $textType = ResourceType::factory()->create(['name' => 'Text', 'slug' => 'text']);
    // A renamed non-physical type must not be excluded by its display name or activation.
    $softwareType = ResourceType::factory()->create(['name' => 'PhysicalObject', 'slug' => 'software', 'is_active' => false]);
    $dataset = Resource::factory()->create(['datacenter_id' => Datacenter::factory()->create()->id]);
    $text = Resource::factory()->create(['resource_type_id' => $textType->id, 'datacenter_id' => Datacenter::factory()->create()->id]);
    LandingPage::factory()->create(['resource_id' => $text->id, 'is_published' => true]);
    $import = Resource::factory()->create(['resource_type_id' => $softwareType->id, 'doi' => null, 'legacy_source' => 'old_datasets']);
    $untyped = Resource::factory()->create(['resource_type_id' => null, 'doi' => null]);

    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    $dataset->dates()->create(['date_type_id' => $created->id, 'start_date' => '2021', 'end_date' => '2022']);
    $text->dates()->create(['date_type_id' => $created->id, 'date_value' => '2023']);
    $import->dates()->create(['date_type_id' => $collected->id, 'start_date' => '2024']);
    $untyped->dates()->create(['date_type_id' => $collected->id, 'date_value' => '2025']);

    $counts = app(DateTypeUsageService::class)->getUsageCounts();

    expect($counts)->toHaveCount(2)
        ->and($counts[$created->id])->toBe(3)
        ->and($counts[$collected->id])->toBe(2)
        ->and($counts)->not->toHaveKey($unused->id);
});

it('excludes physical objects by their stable slug even after renaming', function () {
    $created = DateType::factory()->create(['slug' => 'created']);
    $physicalOnly = DateType::factory()->create(['slug' => 'physical-only']);
    $physicalType = ResourceType::factory()->create(['name' => 'Renamed samples', 'slug' => 'physical-object']);
    $physicalObject = Resource::factory()->create(['resource_type_id' => $physicalType->id]);
    $dataset = Resource::factory()->create();
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    foreach (range(1, 10) as $index) {
        $physicalObject->dates()->create(['date_type_id' => $created->id, 'date_value' => (string) (2000 + $index)]);
    }
    $physicalObject->dates()->create(['date_type_id' => $physicalOnly->id, 'date_value' => '2020']);

    expect(app(DateTypeUsageService::class)->getUsageCounts())->toBe([$created->id => 1]);
});

it('caches even an empty aggregate with a single query', function () {
    DB::enableQueryLog();
    DB::flushQueryLog();

    $service = app(DateTypeUsageService::class);
    expect($service->getUsageCounts())->toBe([])
        ->and(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    expect($service->getUsageCounts())->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('keeps the snapshot until seven days expire and then refreshes it', function (bool $initiallyEmpty) {
    $this->freezeTime();
    $created = DateType::factory()->create(['slug' => 'created']);
    $dataset = Resource::factory()->create();
    if (! $initiallyEmpty) {
        $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    }
    $service = app(DateTypeUsageService::class);
    $initial = $initiallyEmpty ? [] : [$created->id => 1];

    expect($service->getUsageCounts())->toBe($initial);
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2021']);

    $this->travel(7)->days();
    $this->travel(-1)->seconds();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($service->getUsageCounts())->toBe($initial)
        ->and(DB::getQueryLog())->toBe([]);

    $this->travel(1)->seconds();
    expect($service->getUsageCounts())->toBe([$created->id => $initiallyEmpty ? 1 : 2])
        ->and(DB::getQueryLog())->toHaveCount(1);

    DB::flushQueryLog();
    expect($service->getUsageCounts())->toBe([$created->id => $initiallyEmpty ? 1 : 2])
        ->and(DB::getQueryLog())->toBe([]);
})->with([false, true]);

it('refreshes deleted dates and resources, changed date types and resource reclassification on expiry', function () {
    $this->freezeTime();
    $created = DateType::factory()->create(['slug' => 'created']);
    $collected = DateType::factory()->create(['slug' => 'collected']);
    $physicalType = ResourceType::factory()->create(['name' => 'PhysicalObject', 'slug' => 'physical-object']);
    $resources = Resource::factory()->count(4)->create();
    $dates = $resources->map(fn (Resource $resource) => $resource->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']));
    $service = app(DateTypeUsageService::class);

    expect($service->getUsageCounts())->toBe([$created->id => 4]);
    $dates[0]->delete();
    $resources[1]->delete();
    $dates[2]->update(['date_type_id' => $collected->id]);
    $resources[3]->update(['resource_type_id' => $physicalType->id]);
    expect($service->getUsageCounts())->toBe([$created->id => 4]);

    $this->travel(7)->days();
    expect($service->getUsageCounts())->toBe([$collected->id => 1]);
});

it('survives resource cache invalidation and supports targeted resets', function () {
    $created = DateType::factory()->create();
    $dataset = Resource::factory()->create();
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    $service = app(DateTypeUsageService::class);
    expect($service->getUsageCounts())->toBe([$created->id => 1]);

    app(ResourceCacheService::class)->invalidateAllResourceCaches();
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2021']);
    expect($service->getUsageCounts())->toBe([$created->id => 1]);

    expect(CacheKey::EDITOR_DATE_TYPE_USAGE->forget())->toBeTrue()
        ->and($service->getUsageCounts())->toBe([$created->id => 2]);
});

it('supports the database cache fallback without tags including expiry and targeted resets', function () {
    config(['cache.default' => 'database']);
    $this->freezeTime();
    $created = DateType::factory()->create();
    $dataset = Resource::factory()->create();
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    $service = app(DateTypeUsageService::class);

    expect($service->getUsageCounts())->toBe([$created->id => 1]);
    // Bulk imports can bypass model events and leave the cached snapshot intact.
    DB::table('dates')->insert(['resource_id' => $dataset->id, 'date_type_id' => $created->id, 'date_value' => '2021']);
    expect($service->getUsageCounts())->toBe([$created->id => 1]);

    $this->travel(7)->days();
    expect($service->getUsageCounts())->toBe([$created->id => 2]);
    expect(CacheKey::EDITOR_DATE_TYPE_USAGE->forget())->toBeTrue()
        ->and($service->getUsageCounts())->toBe([$created->id => 2]);

    // The pre-existing non-tagged resource invalidation flushes the whole store.
    app(ResourceCacheService::class)->invalidateAllResourceCaches();
    expect(Cache::has(CacheKey::EDITOR_DATE_TYPE_USAGE->key()))->toBeFalse();
});

it('refreshes on manual vocabulary and complete cache resets', function (string $category) {
    $created = DateType::factory()->create();
    $dataset = Resource::factory()->create();
    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2020']);
    $service = app(DateTypeUsageService::class);
    expect($service->getUsageCounts())->toBe([$created->id => 1]);

    $dataset->dates()->create(['date_type_id' => $created->id, 'date_value' => '2021']);
    $this->artisan('cache:clear-app', ['category' => $category])->assertSuccessful();
    expect($service->getUsageCounts())->toBe([$created->id => 2]);
})->with(['vocabularies', 'all']);
