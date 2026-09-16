<?php

declare(strict_types=1);

use App\Enums\CacheKey;
use App\Models\Datacenter;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Observers\AssistanceDatacenterOptionsObserver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

covers(AssistanceDatacenterOptionsObserver::class);

beforeEach(function (): void {
    Config::set('cache.default', 'array');
    Cache::flush();
});

function seedAssistanceDatacenterOptionsCache(): void
{
    $cacheKey = CacheKey::ASSISTANCE_DATACENTER_OPTIONS;
    $repository = method_exists(Cache::getStore(), 'tags')
        ? Cache::tags($cacheKey->tags())
        : Cache::store();

    $repository->put($cacheKey->key(), [
        ['id' => 1, 'name' => 'Cached Datacenter'],
    ]);
}

function hasAssistanceDatacenterOptionsCache(): bool
{
    $cacheKey = CacheKey::ASSISTANCE_DATACENTER_OPTIONS;
    $repository = method_exists(Cache::getStore(), 'tags')
        ? Cache::tags($cacheKey->tags())
        : Cache::store();

    return $repository->has($cacheKey->key());
}

it('invalidates cached options when a Datacenter is renamed or deleted', function () {
    $datacenter = Datacenter::factory()->create();
    seedAssistanceDatacenterOptionsCache();

    $datacenter->update(['name' => 'Renamed Datacenter']);

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();

    seedAssistanceDatacenterOptionsCache();
    $datacenter->delete();

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();
});

it('invalidates cached options for relevant creator relationship changes', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    seedAssistanceDatacenterOptionsCache();

    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();

    $replacement = Person::factory()->create();
    seedAssistanceDatacenterOptionsCache();
    $creator->update(['creatorable_id' => $replacement->id]);

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();

    seedAssistanceDatacenterOptionsCache();
    $creator->delete();

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();
});

it('invalidates cached options for relevant contributor relationship changes', function () {
    $resource = Resource::factory()->create();
    $person = Person::factory()->create();
    seedAssistanceDatacenterOptionsCache();

    $contributor = ResourceContributor::create([
        'resource_id' => $resource->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();

    $replacement = Person::factory()->create();
    seedAssistanceDatacenterOptionsCache();
    $contributor->update(['contributorable_id' => $replacement->id]);

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();

    seedAssistanceDatacenterOptionsCache();
    $contributor->delete();

    expect(hasAssistanceDatacenterOptionsCache())->toBeFalse();
});

it('keeps cached options for unrelated creator metadata changes', function () {
    $creator = ResourceCreator::factory()->create();
    seedAssistanceDatacenterOptionsCache();

    $creator->update(['position' => 2]);

    expect(hasAssistanceDatacenterOptionsCache())->toBeTrue();
});
