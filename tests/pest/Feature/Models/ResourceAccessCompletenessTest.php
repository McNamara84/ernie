<?php

declare(strict_types=1);

use App\Enums\AccessLevel;
use App\Models\DateType;
use App\Models\Description;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Models\Right;
use App\Models\Title;

covers(Resource::class);

function resourceCompleteExceptForAccess(?AccessLevel $accessLevel): Resource
{
    $resource = Resource::factory()->create([
        'access_level' => $accessLevel,
    ]);

    Title::factory()->create(['resource_id' => $resource->id]);
    ResourceCreator::factory()->create(['resource_id' => $resource->id]);
    Description::factory()->abstract()->create(['resource_id' => $resource->id]);
    $resource->rights()->attach(Right::factory()->create());

    return $resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'dates.dateType',
    ]);
}

test('an access level is mandatory for resource completeness', function (): void {
    expect(resourceCompleteExceptForAccess(null)->isComplete())->toBeFalse();
});

test('non-embargoed access levels need no Available date', function (AccessLevel $accessLevel): void {
    expect(resourceCompleteExceptForAccess($accessLevel)->isComplete())->toBeTrue();
})->with([
    AccessLevel::OPEN,
    AccessLevel::RESTRICTED,
    AccessLevel::METADATA_ONLY,
]);

test('embargoed access requires a non-empty Available date', function (): void {
    $resource = resourceCompleteExceptForAccess(AccessLevel::EMBARGOED);

    expect($resource->isComplete())->toBeFalse();

    $available = DateType::firstOrCreate(
        ['slug' => 'Available'],
        ['name' => 'Available', 'is_active' => true],
    );
    $resource->dates()->create([
        'date_type_id' => $available->id,
        'start_date' => '2027-01-01',
    ]);

    expect($resource->fresh([
        'titles.titleType',
        'creators',
        'rights',
        'descriptions.descriptionType',
        'dates.dateType',
    ])->isComplete())->toBeTrue();
});
