<?php

declare(strict_types=1);

use App\Models\Person;
use App\Services\Entities\PersonService;

describe('PersonService', function () {
    beforeEach(function () {
        $this->service = new PersonService;
    });

    describe('findOrCreate', function () {
        it('creates a new person when none exists', function () {
            $data = [
                'firstName' => 'John',
                'lastName' => 'Doe',
            ];

            $person = $this->service->findOrCreate($data);

            expect($person)->toBeInstanceOf(Person::class);
            expect($person->exists)->toBeTrue();
            expect($person->given_name)->toBe('John');
            expect($person->family_name)->toBe('Doe');
        });

        it('finds existing person by name', function () {
            $existing = Person::factory()->create([
                'given_name' => 'Jane',
                'family_name' => 'Smith',
            ]);

            $data = [
                'firstName' => 'Jane',
                'lastName' => 'Smith',
            ];

            $person = $this->service->findOrCreate($data);

            expect($person->id)->toBe($existing->id);
        });

        it('finds existing person by ORCID', function () {
            $orcid = '0000-0002-1234-5678';
            $existing = Person::factory()->create([
                'given_name' => 'Original',
                'family_name' => 'Name',
                'name_identifier' => $orcid,
                'name_identifier_scheme' => 'ORCID',
            ]);

            $data = [
                'orcid' => $orcid,
                'firstName' => 'Different',
                'lastName' => 'Name',
            ];

            $person = $this->service->findOrCreate($data);

            expect($person->id)->toBe($existing->id);
            // Should not update existing person's name
            expect($person->given_name)->toBe('Original');
            expect($person->family_name)->toBe('Name');
        });

        it('prioritizes ORCID search over name search', function () {
            $orcid = '0000-0002-1234-5678';

            // Create person with ORCID
            $personWithOrcid = Person::factory()->create([
                'given_name' => 'ORCID',
                'family_name' => 'Person',
                'name_identifier' => $orcid,
            ]);

            // Create person with same name but no ORCID
            Person::factory()->create([
                'given_name' => 'John',
                'family_name' => 'Doe',
                'name_identifier' => null,
            ]);

            $data = [
                'orcid' => $orcid,
                'firstName' => 'John',
                'lastName' => 'Doe',
            ];

            $person = $this->service->findOrCreate($data);

            // Should find by ORCID, not by name
            expect($person->id)->toBe($personWithOrcid->id);
        });

        it('sets ORCID scheme when creating person with ORCID', function () {
            $orcid = '0000-0002-9999-8888';
            $data = [
                'orcid' => $orcid,
                'firstName' => 'New',
                'lastName' => 'Person',
            ];

            $person = $this->service->findOrCreate($data);

            expect($person->name_identifier)->toBe($orcid);
            expect($person->name_identifier_scheme)->toBe('ORCID');
        });

        it('handles missing firstName gracefully', function () {
            $data = [
                'lastName' => 'OnlyLastName',
            ];

            $person = $this->service->findOrCreate($data);

            expect($person->given_name)->toBeNull();
            expect($person->family_name)->toBe('OnlyLastName');
        });

        it('does not update existing person data', function () {
            $existing = Person::factory()->create([
                'given_name' => 'Original',
                'family_name' => 'Person',
            ]);

            $data = [
                'firstName' => 'Updated',
                'lastName' => 'Person',
            ];

            // This should find by lastName (since firstName differs)
            // and NOT update the existing record
            $person = $this->service->findOrCreate([
                'firstName' => 'Original',
                'lastName' => 'Person',
            ]);

            expect($person->id)->toBe($existing->id);
            expect($person->given_name)->toBe('Original');
        });
    });
});
