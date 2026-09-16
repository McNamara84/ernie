<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\Datacenter;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Observers\AssistanceDatacenterOptionsObserver;
use App\Services\Assistance\AssistanceDatacenterOptionsCacheInvalidationService;
use Illuminate\Database\Eloquent\Model;

covers(AssistanceDatacenterOptionsObserver::class);

beforeEach(function (): void {
    $this->cacheInvalidationService = Mockery::mock(AssistanceDatacenterOptionsCacheInvalidationService::class);
    $this->observer = new AssistanceDatacenterOptionsObserver($this->cacheInvalidationService);
});

it('routes dependency creation and deletion through the transaction-aware invalidator', function (): void {
    $affiliation = new Affiliation;

    $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')->twice();

    $this->observer->created($affiliation);
    $this->observer->deleted($affiliation);
});

it('schedules invalidation for relevant dependency updates', function (
    string $modelClass,
    array $original,
    string $attribute,
    mixed $value,
): void {
    /** @var Model $model */
    $model = new $modelClass;
    $model->forceFill($original);
    $model->syncOriginal();
    $model->setAttribute($attribute, $value);
    $model->syncChanges();

    $this->cacheInvalidationService->shouldReceive('scheduleAfterCommit')->once();

    $this->observer->updated($model);
})->with([
    'affiliation owner' => [
        Affiliation::class,
        ['affiliatable_type' => ResourceCreator::class, 'affiliatable_id' => 1],
        'affiliatable_id',
        2,
    ],
    'creator relationship' => [
        ResourceCreator::class,
        ['resource_id' => 1, 'creatorable_type' => 'person', 'creatorable_id' => 1],
        'creatorable_id',
        2,
    ],
    'contributor relationship' => [
        ResourceContributor::class,
        ['resource_id' => 1, 'contributorable_type' => 'person', 'contributorable_id' => 1],
        'contributorable_id',
        2,
    ],
    'Datacenter name' => [
        Datacenter::class,
        ['name' => 'Old Datacenter'],
        'name',
        'Renamed Datacenter',
    ],
]);

it('ignores unrelated dependency metadata updates', function (
    string $modelClass,
    array $original,
    string $attribute,
    mixed $value,
): void {
    /** @var Model $model */
    $model = new $modelClass;
    $model->forceFill($original);
    $model->syncOriginal();
    $model->setAttribute($attribute, $value);
    $model->syncChanges();

    $this->cacheInvalidationService->shouldNotReceive('scheduleAfterCommit');

    $this->observer->updated($model);
})->with([
    'creator position' => [ResourceCreator::class, ['position' => 1], 'position', 2],
    'affiliation name' => [Affiliation::class, ['name' => 'Old Name'], 'name', 'New Name'],
]);
