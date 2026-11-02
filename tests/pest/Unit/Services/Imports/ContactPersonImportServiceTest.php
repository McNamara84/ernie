<?php

declare(strict_types=1);

use App\Services\Imports\ContactPersonImportService;

function importContactPerson(array $overrides = []): array
{
    return array_replace([
        'type' => 'person', 'firstName' => 'Jane', 'lastName' => 'Doe', 'orcid' => '',
        'roles' => ['Contact Person'], 'affiliations' => [],
    ], $overrides);
}

it('never creates an author from standalone contacts and preserves their order and metadata', function () {
    $contacts = [importContactPerson(['email' => 'jane@example.org']), importContactPerson(['lastName' => 'Other'])];
    $result = (new ContactPersonImportService)->resolve([], $contacts);
    expect($result)->toBe(['authors' => [], 'contributors' => $contacts]);
});

it('does not enrich an author identity from an ordinary contributor without a contact role', function () {
    $author = importContactPerson(['roles' => []]);
    $result = (new ContactPersonImportService)->resolve([$author], [
        importContactPerson(['roles' => ['Data Collector'], 'orcid' => '0000-0002-1825-0097']),
    ]);
    expect($result['authors'])->toBe([$author])
        ->and($result['contributors'])->toHaveCount(1);
});

it('matches a contact by normalized identity without replacing the authors name', function (string $authorOrcid, string $contactOrcid) {
    $author = importContactPerson(['orcid' => $authorOrcid, 'roles' => [], 'affiliations' => [['value' => 'First', 'rorId' => null]]]);
    $contact = importContactPerson(['orcid' => $contactOrcid, 'email' => 'jane@example.org', 'website' => 'https://example.org/jane',
        'affiliations' => [['value' => 'First', 'rorId' => null], ['value' => 'Second', 'rorId' => null]]]);
    $result = (new ContactPersonImportService)->resolve([$author], [$contact]);
    expect($result['authors'])->toHaveCount(1)
        ->and($result['authors'][0]['isContact'])->toBeTrue()
        ->and($result['authors'][0]['email'])->toBe('jane@example.org')
        ->and($result['authors'][0]['website'])->toBe('https://example.org/jane')
        ->and(array_column($result['authors'][0]['affiliations'], 'value'))->toBe(['First', 'Second'])
        ->and($result['contributors'])->toBeEmpty();
    if ($authorOrcid !== '' || $contactOrcid !== '') {
        expect($result['authors'][0]['orcid'])->toBe('0000-0002-1825-0097');
    }
})->with([
    'names' => ['', ''],
    'ORCID URL' => ['https://orcid.org/0000-0002-1825-0097', '0000-0002-1825-0097'],
    'contact ORCID only' => ['', '0000-0002-1825-0097'],
    'author ORCID only' => ['0000-0002-1825-0097', ''],
]);

it('enriches the original contact name before merging an ORCID match with a renamed author', function () {
    $author = importContactPerson(['lastName' => 'Renamed', 'orcid' => '0000-0002-1825-0097']);
    $contact = importContactPerson(['orcid' => '0000-0002-1825-0097']);
    $result = (new ContactPersonImportService)->resolve([$author], [$contact], [
        'doe, jane' => ['email' => 'jane@example.org', 'website' => 'https://example.org/jane'],
    ]);
    expect($result['authors'][0]['lastName'])->toBe('Renamed')
        ->and($result['authors'][0]['email'])->toBe('jane@example.org')
        ->and($result['contributors'])->toBeEmpty();
});

it('keeps existing author contact values when a matching contact contains different values', function () {
    $result = (new ContactPersonImportService)->resolve(
        [importContactPerson(['email' => 'curated@example.org', 'website' => 'https://example.org/curated'])],
        [importContactPerson(['email' => 'imported@example.org', 'website' => ''])],
    );
    expect($result['authors'][0]['email'])->toBe('curated@example.org')
        ->and($result['authors'][0]['website'])->toBe('https://example.org/curated');
});

it('does not join conflicting ORCIDs through an identifierless name in any input order', function (array $order) {
    $entries = [
        importContactPerson(['orcid' => '0000-0002-1825-0097']),
        importContactPerson(['roles' => ['Data Collector']]),
        importContactPerson(['orcid' => '0000-0002-1694-233X']),
    ];
    $result = (new ContactPersonImportService)->resolve([], array_map(fn (int $index) => $entries[$index], $order), [
        'doe, jane' => ['email' => 'ambiguous@example.org', 'website' => ''],
    ]);
    expect($result['contributors'])->toHaveCount(3);
    foreach ($result['contributors'] as $contributor) {
        expect($contributor['email'] ?? '')->toBe('');
    }
})->with([[[0, 1, 2]], [[0, 2, 1]], [[1, 0, 2]], [[1, 2, 0]], [[2, 0, 1]], [[2, 1, 0]]]);

it('does not assign a contact to one of several compatible authors', function () {
    $result = (new ContactPersonImportService)->resolve([importContactPerson(), importContactPerson()], [importContactPerson()]);
    expect($result['authors'])->toHaveCount(2)
        ->and($result['authors'][0]['isContact'] ?? false)->toBeFalse()
        ->and($result['authors'][1]['isContact'] ?? false)->toBeFalse()
        ->and($result['contributors'])->toHaveCount(1);
});

it('does not match different ORCIDs even when the names are identical', function () {
    $result = (new ContactPersonImportService)->resolve(
        [importContactPerson(['orcid' => '0000-0002-1825-0097'])],
        [importContactPerson(['orcid' => '0000-0002-1694-233X'])],
    );
    expect($result['authors'][0]['isContact'] ?? false)->toBeFalse()
        ->and($result['contributors'])->toHaveCount(1);
});

it('does not infer identity from incomplete names or malformed ORCID URLs', function (array $overrides) {
    $result = (new ContactPersonImportService)->resolve([importContactPerson($overrides)], [importContactPerson($overrides)]);
    expect($result['authors'][0]['isContact'] ?? false)->toBeFalse()
        ->and($result['contributors'])->toHaveCount(1);
})->with([
    [['firstName' => '']], [['lastName' => '']],
    [['firstName' => '', 'lastName' => '', 'orcid' => 'https://orcid.org/']],
]);

it('preserves additional roles and contact metadata regardless of their source order', function (bool $reverse, bool $isAuthor) {
    $contact = importContactPerson(['email' => 'jane@example.org', 'affiliations' => [['value' => 'Contact affiliation']]]);
    $collector = importContactPerson(['roles' => ['Data Collector'], 'affiliations' => [['value' => 'Collector affiliation']]]);
    $contributors = $reverse ? [$collector, $contact] : [$contact, $collector];
    $result = (new ContactPersonImportService)->resolve($isAuthor ? [importContactPerson(['roles' => []])] : [], $contributors);
    expect($result['contributors'])->toHaveCount(1);
    $roles = $result['contributors'][0]['roles'];
    expect($roles)->toContain('Data Collector');
    if ($isAuthor) {
        expect($roles)->toBe(['Data Collector'])
            ->and($result['authors'][0]['email'])->toBe('jane@example.org')
            ->and($result['authors'][0]['affiliations'])->toHaveCount(2);
    } else {
        expect($roles)->toContain('Contact Person')
            ->and($result['contributors'][0]['email'])->toBe('jane@example.org')
            ->and($result['contributors'][0]['affiliations'])->toHaveCount(2);
    }
})->with([false, true])->with([false, true]);

it('keeps organizational contacts as institutions', function () {
    $institution = ['type' => 'institution', 'institutionName' => 'Contact Institute', 'roles' => ['Contact Person'], 'affiliations' => []];
    expect((new ContactPersonImportService)->resolve([$institution], [$institution]))
        ->toBe(['authors' => [$institution], 'contributors' => [$institution]]);
});

it('recognizes legacy compound roles and normalizes whitespace for matching', function () {
    $result = (new ContactPersonImportService)->resolve(
        [importContactPerson(['firstName' => ' Jane ', 'lastName' => 'DOE'])],
        [importContactPerson(['roles' => ['ContactPerson', 'Data Curator']])],
    );
    expect($result['authors'][0]['isContact'])->toBeTrue()
        ->and($result['contributors'][0]['roles'])->toBe(['Data Curator']);
});
