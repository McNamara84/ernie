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
            // scheme_uri is not set by PersonService (only name_identifier + scheme)
            expect($person->scheme_uri)->toBeNull();
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
                'lastName' => '',
            ]);

            expect($person->exists)->toBeTrue();
            expect($person->given_name)->toBe('Charles');
            // family_name is NOT NULL in DB, so it must be an empty string
            expect($person->family_name)->toBe('');
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

    describe('findOrCreateWithoutStructuredName', function () {
        it('creates a distinct nameless person without an ORCID', function () {
            $first = $this->service->findOrCreateWithoutStructuredName();
            $second = $this->service->findOrCreateWithoutStructuredName();

            expect($first->id)->not->toBe($second->id)
                ->and($first->given_name)->toBeNull()
                ->and($first->family_name)->toBe('')
                ->and($first->name_identifier)->toBeNull();
        });

        it('creates a nameless person with an ORCID', function () {
            $orcid = '0000-0002-1825-0097';

            $person = $this->service->findOrCreateWithoutStructuredName($orcid);

            expect($person->given_name)->toBeNull()
                ->and($person->family_name)->toBe('')
                ->and($person->name_identifier)->toBe('https://orcid.org/'.$orcid)
                ->and($person->name_identifier_scheme)->toBe('ORCID');
        });

        it('reuses stored ORCID variants without changing the global name', function (
            string $storedOrcid,
            string $submittedOrcid,
        ) {
            $orcid = '0000-0002-1825-0097';
            $existing = Person::factory()->create([
                'given_name' => 'Global',
                'family_name' => 'Identity',
                'name_identifier' => str_replace('{orcid}', $orcid, $storedOrcid),
                'name_identifier_scheme' => 'ORCID',
            ]);

            $found = $this->service->findOrCreateWithoutStructuredName(
                str_replace('{orcid}', $orcid, $submittedOrcid),
            );

            expect($found->id)->toBe($existing->id)
                ->and($found->given_name)->toBe('Global')
                ->and($found->family_name)->toBe('Identity')
                ->and(Person::query()->count())->toBe(1);
        })->with([
            'canonical stored and bare submitted' => ['https://orcid.org/{orcid}', '{orcid}'],
            'bare stored and canonical submitted' => ['{orcid}', 'https://orcid.org/{orcid}'],
            'HTTP www stored and bare submitted' => ['http://www.orcid.org/{orcid}', '{orcid}'],
            'prefixless URL stored and canonical submitted' => ['orcid.org/{orcid}', 'https://orcid.org/{orcid}'],
        ]);
    });

    it('creates a person without rediscovering an ORCID person by name', function () {
        $existing = Person::factory()->create([
            'given_name' => 'Philipp',
            'family_name' => 'Sommer',
            'name_identifier' => '0000-0002-1825-0097',
            'name_identifier_scheme' => 'ORCID',
        ]);

        $created = $this->service->createWithoutOrcid('Philipp', 'Sommer');

        expect($created->id)->not->toBe($existing->id)
            ->and($created->given_name)->toBe('Philipp')
            ->and($created->family_name)->toBe('Sommer')
            ->and($created->name_identifier)->toBeNull();
    });
});
