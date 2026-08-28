<?php

declare(strict_types=1);

use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\LandingPagePersonIdentityResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

covers(LandingPagePersonIdentityResolver::class);

function identityResolverPerson(
    int $id,
    string $givenName = 'Alex',
    string $familyName = 'Example',
    ?string $orcid = null,
    ?string $scheme = null,
): Person {
    $person = new Person;
    $person->forceFill([
        'id' => $id,
        'given_name' => $givenName,
        'family_name' => $familyName,
        'name_identifier' => $orcid,
        'name_identifier_scheme' => $scheme,
    ]);

    return $person;
}

function identityResolverCreator(int $id, Person|Institution $entity): ResourceCreator
{
    $creator = new ResourceCreator;
    $creator->forceFill([
        'id' => $id,
        'position' => $id,
        'creatorable_type' => $entity::class,
        'creatorable_id' => $entity->id,
    ]);
    $creator->setRelation('creatorable', $entity);

    return $creator;
}

function identityResolverContributor(
    int $id,
    Person|Institution $entity,
    bool $isContactPerson = false,
): ResourceContributor {
    $contributor = new ResourceContributor;
    $contributor->forceFill([
        'id' => $id,
        'position' => $id,
        'contributorable_type' => $entity::class,
        'contributorable_id' => $entity->id,
    ]);
    $contributor->setRelation('contributorable', $entity);

    $types = [];
    if ($isContactPerson) {
        $contactType = new ContributorType;
        $contactType->forceFill([
            'id' => 1,
            'name' => 'Contact Person',
            'slug' => 'ContactPerson',
        ]);
        $types[] = $contactType;
    }

    $contributor->setRelation('contributorTypes', new EloquentCollection($types));

    return $contributor;
}

/**
 * @param  list<ResourceCreator>  $creators
 * @param  list<ResourceContributor>  $contributors
 * @return array{creators: array<int, string>, contributors: array<int, string>}
 */
function resolveLandingPageIdentities(array $creators, array $contributors): array
{
    $resource = new Resource;
    $resource->setRelation('creators', new EloquentCollection($creators));
    $resource->setRelation('contributors', new EloquentCollection($contributors));

    return (new LandingPagePersonIdentityResolver)->resolve($resource);
}

test('groups creator and contributor rows that reference the same person entity', function () {
    $person = identityResolverPerson(10);
    $creator = identityResolverCreator(1, $person);
    $contributor = identityResolverContributor(2, $person);

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->toBe($result['creators'][$creator->id]);
});

test('groups different person entities by the same valid ORCID', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(
        10,
        'Alexandra',
        'Creator',
        'https://orcid.org/0000-0002-1825-0097',
        'ORCID',
    ));
    $contributor = identityResolverContributor(2, identityResolverPerson(
        20,
        'A.',
        'Contributor',
        '0000-0002-1825-0097',
        'orcid',
    ));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->toBe($result['creators'][$creator->id]);
});

test('groups a unique contributor and creator by an exact normalized structured name', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, '  JOSÉ   Luis ', 'Gómez-Zapata'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, 'Jose Luis', 'Gomez Zapata'));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->toBe($result['creators'][$creator->id]);
});

test('groups a contact person whose legacy name parts are reordered', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, 'Juan Camilo', 'Gomez-Zapata'));
    $contributor = identityResolverContributor(
        2,
        identityResolverPerson(20, 'Gomez Zapata Juan', 'Camilo'),
        true,
    );

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->toBe($result['creators'][$creator->id]);
});

test('requires the ContactPerson role for a reordered legacy name match', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, 'Juan Camilo', 'Gomez-Zapata'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, 'Gomez Zapata Juan', 'Camilo'));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('does not use reordered matching for names with fewer than three tokens', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, 'John', 'Smith'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, 'Smith', 'John'), true);

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('does not group people with different valid ORCIDs despite an exact name match', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(
        10,
        'Alex',
        'Example',
        '0000-0002-1825-0097',
        'ORCID',
    ));
    $contributor = identityResolverContributor(2, identityResolverPerson(
        20,
        'Alex',
        'Example',
        '0000-0002-1694-233X',
        'ORCID',
    ));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('does not treat an invalid ORCID as positive identity evidence', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, 'Alice', 'Creator', 'not-an-orcid', 'ORCID'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, 'Bob', 'Contributor', 'not-an-orcid', 'ORCID'));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('keeps a name match separate when multiple creator groups are eligible', function () {
    $firstCreator = identityResolverCreator(1, identityResolverPerson(10, 'Alex', 'Example'));
    $secondCreator = identityResolverCreator(2, identityResolverPerson(20, 'Alex', 'Example'));
    $contributor = identityResolverContributor(3, identityResolverPerson(30, 'Alex', 'Example'));

    $result = resolveLandingPageIdentities([$firstCreator, $secondCreator], [$contributor]);

    expect($result['contributors'][$contributor->id])
        ->not->toBe($result['creators'][$firstCreator->id])
        ->not->toBe($result['creators'][$secondCreator->id]);
});

test('keeps similar but non-identical legacy token sets separate', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, 'Juan Camilo', 'Gomez Zapata'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, 'Juan Carlos', 'Gomez Zapata'), true);

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('keeps institutions and people separate even when their numeric ids match', function () {
    $institution = new Institution;
    $institution->forceFill([
        'id' => 10,
        'name' => 'Alex Example',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);
    $creator = identityResolverCreator(1, $institution);
    $contributor = identityResolverContributor(2, identityResolverPerson(10));

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('does not group two creator entities by name alone', function () {
    $firstCreator = identityResolverCreator(1, identityResolverPerson(10, 'Alex', 'Example'));
    $secondCreator = identityResolverCreator(2, identityResolverPerson(20, 'Alex', 'Example'));

    $result = resolveLandingPageIdentities([$firstCreator, $secondCreator], []);

    expect($result['creators'][$firstCreator->id])->not->toBe($result['creators'][$secondCreator->id]);
});

test('groups repeated contributors by strong ORCID evidence', function () {
    $firstContributor = identityResolverContributor(1, identityResolverPerson(
        10,
        'Alex',
        'First',
        '0000-0002-1825-0097',
        'ORCID',
    ));
    $secondContributor = identityResolverContributor(2, identityResolverPerson(
        20,
        'Alexandra',
        'Second',
        'https://orcid.org/0000-0002-1825-0097',
        null,
    ));

    $result = resolveLandingPageIdentities([], [$firstContributor, $secondContributor]);

    expect($result['contributors'][$secondContributor->id])->toBe($result['contributors'][$firstContributor->id]);
});

test('keeps incomplete person names separate without strong identity evidence', function () {
    $creator = identityResolverCreator(1, identityResolverPerson(10, '', 'Example'));
    $contributor = identityResolverContributor(2, identityResolverPerson(20, '', 'Example'), true);

    $result = resolveLandingPageIdentities([$creator], [$contributor]);

    expect($result['contributors'][$contributor->id])->not->toBe($result['creators'][$creator->id]);
});

test('keeps non-person and unresolved legacy rows in safe standalone groups', function () {
    $legacyAliasCreator = new ResourceCreator;
    $legacyAliasCreator->forceFill([
        'id' => 1,
        'creatorable_type' => 'LegacyPersonAlias',
        'creatorable_id' => 9,
    ]);
    $legacyAliasCreator->setRelation('creatorable', null);

    $invalidIdCreator = new ResourceCreator;
    $invalidIdCreator->forceFill([
        'id' => 2,
        'creatorable_type' => Person::class,
        'creatorable_id' => 0,
    ]);
    $invalidIdCreator->setRelation('creatorable', null);

    $emptyTypeCreator = new ResourceCreator;
    $emptyTypeCreator->forceFill([
        'id' => 3,
        'creatorable_type' => '',
        'creatorable_id' => 30,
    ]);
    $emptyTypeCreator->setRelation('creatorable', null);

    $institution = new Institution;
    $institution->forceFill([
        'id' => 40,
        'name' => 'Example Institute',
        'name_identifier' => null,
        'name_identifier_scheme' => null,
    ]);
    $institutionContributor = identityResolverContributor(4, $institution);

    $result = resolveLandingPageIdentities(
        [$legacyAliasCreator, $invalidIdCreator, $emptyTypeCreator],
        [$institutionContributor],
    );

    expect($result['creators'][$legacyAliasCreator->id])->toBe('entity:legacypersonalias:9')
        ->and($result['creators'][$invalidIdCreator->id])->toBe('creator-row:2')
        ->and($result['creators'][$emptyTypeCreator->id])->toBe('creator-row:3')
        ->and($result['contributors'][$institutionContributor->id])->toBe('entity:institution:40');
});
