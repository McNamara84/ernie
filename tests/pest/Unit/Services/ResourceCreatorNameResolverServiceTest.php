<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Creators\ResourceCreatorNameResolverService;

covers(ResourceCreatorNameResolverService::class);

it('prefers a complete resource creator name snapshot over the global person', function (): void {
    $person = Person::factory()->make(['given_name' => 'Philipp', 'family_name' => 'Sommer']);
    $creator = ResourceCreator::factory()->make([
        'name_snapshot' => 'Sommer, Philipp S.',
        'given_name_snapshot' => 'Philipp S.',
        'family_name_snapshot' => 'Sommer',
    ]);

    expect((new ResourceCreatorNameResolverService)->resolve($creator, $person))->toBe([
        'name' => 'Sommer, Philipp S.',
        'given_name' => 'Philipp S.',
        'family_name' => 'Sommer',
        'source' => 'snapshot',
    ]);
});

it('formats structured partial snapshots without inventing missing fields', function (): void {
    $person = Person::factory()->make(['given_name' => 'Global', 'family_name' => 'Person']);
    $creator = ResourceCreator::factory()->make([
        'name_snapshot' => null,
        'given_name_snapshot' => null,
        'family_name_snapshot' => 'Mononym',
    ]);

    expect((new ResourceCreatorNameResolverService)->resolve($creator, $person))->toBe([
        'name' => 'Mononym',
        'given_name' => null,
        'family_name' => 'Mononym',
        'source' => 'snapshot',
    ]);
});

it('falls back to the person for contributors and creators without snapshots', function (): void {
    $person = Person::factory()->make(['given_name' => 'Philipp', 'family_name' => 'Sommer']);
    $service = new ResourceCreatorNameResolverService;

    expect($service->resolve(ResourceCreator::factory()->make(), $person))
        ->toMatchArray(['name' => 'Sommer, Philipp', 'source' => 'person'])
        ->and($service->resolve(new ResourceContributor, $person))
        ->toMatchArray(['name' => 'Sommer, Philipp', 'source' => 'person']);
});

it('keeps an unstructured snapshot unstructured', function (): void {
    $person = Person::factory()->make(['given_name' => 'Global', 'family_name' => 'Person']);
    $creator = ResourceCreator::factory()->make([
        'name_snapshot' => 'The Artist',
        'given_name_snapshot' => null,
        'family_name_snapshot' => null,
    ]);

    expect((new ResourceCreatorNameResolverService)->resolve($creator, $person))->toBe([
        'name' => 'The Artist',
        'given_name' => null,
        'family_name' => null,
        'source' => 'snapshot',
    ]);
});
