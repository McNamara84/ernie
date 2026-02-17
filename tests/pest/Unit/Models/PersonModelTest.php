<?php

declare(strict_types=1);

use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('fullName accessor', function () {
    test('returns "Family, Given" format with both names', function () {
        $person = Person::factory()->create([
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
        ]);

        expect($person->fullName)->toBe('Lovelace, Ada');
    });

    test('returns family name only when given name is absent', function () {
        $person = Person::factory()->create([
            'given_name' => null,
            'family_name' => 'Lovelace',
        ]);

        expect($person->fullName)->toBe('Lovelace');
    });

    test('returns given name only when family name is absent', function () {
        // family_name is NOT NULL in DB, so use in-memory model to test accessor
        $person = new Person(['given_name' => 'Ada', 'family_name' => null]);

        expect($person->fullName)->toBe('Ada');
    });

    test('returns empty string when both names are null', function () {
        // Both columns may be NOT NULL in DB, so use in-memory model to test accessor
        $person = new Person(['given_name' => null, 'family_name' => null]);

        expect($person->fullName)->toBe('');
    });
});

describe('hasOrcid', function () {
    test('returns true when ORCID identifier is present', function () {
        $person = Person::factory()->withOrcid()->create();

        expect($person->hasOrcid())->toBeTrue();
    });

    test('returns false when no identifier', function () {
        $person = Person::factory()->create();

        expect($person->hasOrcid())->toBeFalse();
    });

    test('returns false for non-ORCID identifier scheme', function () {
        $person = Person::factory()->create([
            'name_identifier' => 'some-id',
            'name_identifier_scheme' => 'ROR',
        ]);

        expect($person->hasOrcid())->toBeFalse();
    });
});

describe('orcid accessor', function () {
    test('returns ORCID identifier when present', function () {
        $orcid = 'https://orcid.org/0000-0001-2345-6789';
        $person = Person::factory()->withOrcid($orcid)->create();

        expect($person->orcid)->toBe($orcid);
    });

    test('returns null when no ORCID', function () {
        $person = Person::factory()->create();

        expect($person->orcid)->toBeNull();
    });
});
