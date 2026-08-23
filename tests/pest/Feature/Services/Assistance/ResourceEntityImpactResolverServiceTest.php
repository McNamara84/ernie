<?php

declare(strict_types=1);

use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Assistance\ResourceEntityImpactResolverService;

covers(ResourceEntityImpactResolverService::class);

it('resolves every creator and contributor resource for shared people and institutions', function (): void {
    $person = Person::factory()->create();
    $institution = Institution::factory()->create();
    $resources = Resource::factory()->count(4)->create();

    ResourceCreator::create([
        'resource_id' => $resources[0]->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    ResourceContributor::create([
        'resource_id' => $resources[1]->id,
        'contributorable_type' => Person::class,
        'contributorable_id' => $person->id,
        'position' => 1,
    ]);
    ResourceCreator::create([
        'resource_id' => $resources[2]->id,
        'creatorable_type' => Institution::class,
        'creatorable_id' => $institution->id,
        'position' => 1,
    ]);
    ResourceContributor::create([
        'resource_id' => $resources[3]->id,
        'contributorable_type' => Institution::class,
        'contributorable_id' => $institution->id,
        'position' => 1,
    ]);

    $resolver = app(ResourceEntityImpactResolverService::class);

    expect($resolver->forPersons([$person->id, $person->id]))
        ->toBe([$person->id => [$resources[0]->id, $resources[1]->id]])
        ->and($resolver->forInstitutions([$institution->id]))
        ->toBe([$institution->id => [$resources[2]->id, $resources[3]->id]]);
});

it('resolves affiliations to their owning resource and safely handles missing targets', function (): void {
    $person = Person::factory()->create();
    $resource = Resource::factory()->create();
    $creator = ResourceCreator::create([
        'resource_id' => $resource->id,
        'creatorable_type' => Person::class,
        'creatorable_id' => $person->id,
        'position' => 1,
    ]);
    $affiliation = $creator->affiliations()->create(['name' => 'GFZ']);

    $result = app(ResourceEntityImpactResolverService::class)->forAffiliations([$affiliation->id, 999999]);

    expect($result)->toBe([$affiliation->id => [$resource->id]]);
});
