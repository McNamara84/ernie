<?php

declare(strict_types=1);

use App\Models\Person;
use App\Services\Entities\PersonService;

covers(PersonService::class);

describe('PersonService', function () {
    beforeEach(function () {
        $this->service = new PersonService;
    });

    describe('findOrCreate', function () {
        it('creates a new person when none exists', function () {
            $person = $this->service->findOrCreate([
                'firstName' => 'Albert',
                'lastName' => 'Einstein',
            ]);

            expect($person)->toBeInstanceOf(Person::class);
            expect($person->exists)->toBeTrue();
            expect($person->given_name)->toBe('Albert');
            expect($person->family_name)->toBe('Einstein');
        });

        it('finds existing person by name', function () {
            $existing = Person::factory()->create([
                'given_name' => 'Marie',
                'family_name' => 'Curie',
            ]);

            $found = $this->service->findOrCreate([
                'firstName' => 'Marie',
                'lastName' => 'Curie',
            ]);

            expect($found->id)->toBe($existing->id);
        });

        it('finds existing person by ORCID', function () {
            $orcid = 'https://orcid.org/0000-0001-2345-6789';
            $existing = Person::factory()->withOrcid($orcid)->create([
                'given_name' => 'Max',
                'family_name' => 'Planck',
            ]);

            $found = $this->service->findOrCreate([
                'firstName' => 'Maximilian',
                'lastName' => 'Planck',
                'orcid' => $orcid,
            ]);

            expect($found->id)->toBe($existing->id);
            // Name should NOT be updated for existing persons
            expect($found->given_name)->toBe('Max');
        });

        it('prioritizes ORCID search over name search', function () {
            $orcid = 'https://orcid.org/0000-0001-9999-8888';
            Person::factory()->create([
                'given_name' => 'John',
                'family_name' => 'Doe',
            ]);
            $withOrcid = Person::factory()->withOrcid($orcid)->create([
                'given_name' => 'John',
                'family_name' => 'Smith',
            ]);

            $found = $this->service->findOrCreate([
                'firstName' => 'John',
                'lastName' => 'Doe',
                'orcid' => $orcid,
            ]);

            expect($found->id)->toBe($withOrcid->id);
        });

        it('creates person with ORCID when not found', function () {
            $orcid = 'https://orcid.org/0000-0002-1234-5678';

            $person = $this->service->findOrCreate([
                'firstName' => 'Niels',
                'lastName' => 'Bohr',
                'orcid' => $orcid,
            ]);

            expect($person->name_identifier)->toBe($orcid);
            expect($person->name_identifier_scheme)->toBe('ORCID');
            expect($person->scheme_uri)->toBe('https://orcid.org/');
        });

        it('handles missing first name', function () {
            $person = $this->service->findOrCreate([
                'lastName' => 'Darwin',
            ]);

            expect($person->exists)->toBeTrue();
            expect($person->family_name)->toBe('Darwin');
            expect($person->given_name)->toBeNull();
        });

        it('handles missing last name', function () {
            $person = $this->service->findOrCreate([
                'firstName' => 'Charles',
            ]);

            expect($person->exists)->toBeTrue();
            expect($person->given_name)->toBe('Charles');
            expect($person->family_name)->toBeNull();
        });

        it('handles empty orcid by falling back to name search', function () {
            $existing = Person::factory()->create([
                'given_name' => 'Isaac',
                'family_name' => 'Newton',
            ]);

            $found = $this->service->findOrCreate([
                'firstName' => 'Isaac',
                'lastName' => 'Newton',
                'orcid' => '',
            ]);

            expect($found->id)->toBe($existing->id);
        });
    });
});
