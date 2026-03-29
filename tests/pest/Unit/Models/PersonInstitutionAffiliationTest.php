<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\Person;
use App\Models\Institution;
use App\Models\ResourceCreator;
use App\Models\ResourceContributor;

covers(Person::class, Institution::class, Affiliation::class);

describe('Person model', function () {
    it('has correct fillable attributes', function () {
        $person = new Person;

        expect($person->getFillable())->toBe([
            'given_name',
            'family_name',
            'name_identifier',
            'name_identifier_scheme',
            'scheme_uri',
        ]);
    });

    it('generates full name in DataCite format', function () {
        $person = Person::factory()->make([
            'given_name' => 'Albert',
            'family_name' => 'Einstein',
        ]);

        expect($person->full_name)->toBe('Einstein, Albert');
    });

    it('returns only family name when given name is missing', function () {
        $person = Person::factory()->make([
            'given_name' => null,
            'family_name' => 'Einstein',
        ]);

        expect($person->full_name)->toBe('Einstein');
    });

    it('returns only given name when family name is missing', function () {
        $person = Person::factory()->make([
            'given_name' => 'Albert',
            'family_name' => null,
        ]);

        expect($person->full_name)->toBe('Albert');
    });

    it('returns empty string when both names are missing', function () {
        $person = Person::factory()->make([
            'given_name' => null,
            'family_name' => null,
        ]);

        expect($person->full_name)->toBe('');
    });

    it('detects ORCID presence', function () {
        $person = Person::factory()->withOrcid()->make();

        expect($person->hasOrcid())->toBeTrue();
    });

    it('detects missing ORCID', function () {
        $person = Person::factory()->make();

        expect($person->hasOrcid())->toBeFalse();
    });

    it('returns ORCID via accessor', function () {
        $orcid = 'https://orcid.org/0000-0001-2345-6789';
        $person = Person::factory()->withOrcid($orcid)->make();

        expect($person->orcid)->toBe($orcid);
    });

    it('returns null ORCID when not present', function () {
        $person = Person::factory()->make();

        expect($person->orcid)->toBeNull();
    });

    it('has resourceCreators relationship', function () {
        $person = Person::factory()->create();

        expect($person->resourceCreators())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });

    it('has resourceContributors relationship', function () {
        $person = Person::factory()->create();

        expect($person->resourceContributors())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });
});

describe('Institution model', function () {
    it('has correct fillable attributes', function () {
        $institution = new Institution;

        expect($institution->getFillable())->toBe([
            'name',
            'name_identifier',
            'name_identifier_scheme',
            'scheme_uri',
        ]);
    });

    it('detects ROR identifier', function () {
        $institution = Institution::factory()->withRor()->make();

        expect($institution->hasRor())->toBeTrue();
    });

    it('detects missing ROR', function () {
        $institution = Institution::factory()->make();

        expect($institution->hasRor())->toBeFalse();
    });

    it('returns ROR ID via accessor', function () {
        $rorId = 'https://ror.org/04z8jg394';
        $institution = Institution::factory()->withRor($rorId)->make();

        expect($institution->ror_id)->toBe($rorId);
    });

    it('detects MSL laboratory', function () {
        $institution = Institution::factory()->make([
            'name_identifier' => 'lab-001',
            'name_identifier_scheme' => 'labid',
        ]);

        expect($institution->isLaboratory())->toBeTrue();
    });

    it('detects non-laboratory', function () {
        $institution = Institution::factory()->withRor()->make();

        expect($institution->isLaboratory())->toBeFalse();
    });

    it('has resourceCreators relationship', function () {
        $institution = Institution::factory()->create();

        expect($institution->resourceCreators())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });

    it('has resourceContributors relationship', function () {
        $institution = Institution::factory()->create();

        expect($institution->resourceContributors())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class);
    });
});

describe('Affiliation model', function () {
    it('has correct fillable attributes', function () {
        $affiliation = new Affiliation;

        expect($affiliation->getFillable())->toContain('name', 'identifier', 'identifier_scheme', 'scheme_uri');
    });

    it('detects ROR identifier', function () {
        $affiliation = new Affiliation;
        $affiliation->identifier = 'https://ror.org/04z8jg394';
        $affiliation->identifier_scheme = 'ROR';

        expect($affiliation->hasRor())->toBeTrue();
    });

    it('detects missing ROR', function () {
        $affiliation = new Affiliation;
        $affiliation->identifier = null;
        $affiliation->identifier_scheme = null;

        expect($affiliation->hasRor())->toBeFalse();
    });

    it('has affiliatable morphTo relationship', function () {
        $affiliation = new Affiliation;

        expect($affiliation->affiliatable())
            ->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphTo::class);
    });
});
