<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\ContactPersons\ContactPersonIdentityService;

covers(ContactPersonIdentityService::class);

it('normalizes valid ORCID forms without joining other identities', function (?string $identifier, ?string $scheme, ?string $expected): void {
    $person = new Person(['given_name' => 'Ada', 'family_name' => 'Example', 'name_identifier' => $identifier, 'name_identifier_scheme' => $scheme]);
    $person->id = 42;
    $point = app(ContactPersonIdentityService::class)->point($person, new ResourceContributor(['email' => ' Ada@Example.test ']));
    expect($point['orcid'])->toBe($expected)
        ->and($point['identity_key'])->toBe($expected === null ? 'person:42' : 'orcid:'.$expected)
        ->and($point['email'])->toBe('Ada@Example.test')
        ->and($point['email_normalized'])->toBe('ada@example.test');
})->with([
    ['0000-0002-1825-0097', 'ORCID', '0000-0002-1825-0097'],
    ['https://orcid.org/0000-0002-1825-0097', 'orcid', '0000-0002-1825-0097'],
    [' HTTP://WWW.ORCID.ORG/0000-0002-1825-0097 ', null, '0000-0002-1825-0097'],
    ['orcid.org/0000-0002-1825-0097', '', '0000-0002-1825-0097'],
    ['0000-0002-1694-233x', 'ORCID', '0000-0002-1694-233X'],
    ['0000-0002-1825-0098', 'ORCID', null],
    ['0000-0002-1825-0097', 'ISNI', null],
    ['https://example.test/0000-0002-1825-0097', 'ORCID', null],
    [null, null, null],
]);

it('preserves snapshots and name variants while deduplicating address casing and empty addresses', function (): void {
    $person = new Person(['given_name' => 'Ada', 'family_name' => 'Example']);
    $person->id = 7;
    $service = app(ContactPersonIdentityService::class);
    $creator = new ResourceCreator(['name_snapshot' => 'Example, A.', 'email' => 'ADA@example.test']);
    $first = $service->point($person, $creator);
    $creator->email = ' ada@example.test ';
    expect($service->point($person, $creator)['variant_key'])->toBe($first['variant_key']);
    $creator->name_snapshot = 'Example, Ada';
    expect($service->point($person, $creator)['variant_key'])->not->toBe($first['variant_key']);
    $creator->email = '   ';
    $empty = $service->point($person, $creator);
    $creator->email = null;
    expect($service->point($person, $creator)['variant_key'])->toBe($empty['variant_key'])
        ->and($empty['email'])->toBeNull()
        ->and($first['display_name'])->toBe('Example, A.');
});
