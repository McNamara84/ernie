<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\Institution;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\DataCite\Mapping\DataCitePartyMapper;

covers(DataCitePartyMapper::class);

beforeEach(function (): void {
    $this->mapper = app(DataCitePartyMapper::class);
});

it('builds person creator data with identifiers and affiliations', function (): void {
    $person = Person::factory()->make([
        'given_name' => 'Jane',
        'family_name' => 'Doe',
        'name_identifier' => 'https://orcid.org/0000-0002-1234-5678',
        'name_identifier_scheme' => 'ORCID',
    ]);

    $affiliation = (new Affiliation)->forceFill([
        'name' => 'GFZ Potsdam',
        'identifier' => 'https://ror.org/04z8jg394',
        'identifier_scheme' => 'ROR',
        'scheme_uri' => null,
    ]);

    $creator = new ResourceCreator;
    $creator->setRelation('affiliations', collect([$affiliation]));

    $data = $this->mapper->buildPersonCreatorData($creator, $person);

    expect($data)->toMatchArray([
        'name' => 'Doe, Jane',
        'nameType' => 'Personal',
        'givenName' => 'Jane',
        'familyName' => 'Doe',
        'nameIdentifiers' => [[
            'nameIdentifier' => 'https://orcid.org/0000-0002-1234-5678',
            'nameIdentifierScheme' => 'ORCID',
            'schemeUri' => 'https://orcid.org/',
        ]],
        'affiliation' => [[
            'name' => 'GFZ Potsdam',
            'affiliationIdentifier' => 'https://ror.org/04z8jg394',
            'affiliationIdentifierScheme' => 'ROR',
            'schemeURI' => 'https://ror.org/',
        ]],
    ]);
});

it('uses an explicit contributor type for repeated DataCite contributor roles', function (): void {
    $person = Person::factory()->make([
        'given_name' => 'Alex',
        'family_name' => 'Miller',
    ]);

    $storedType = new ContributorType;
    $storedType->slug = 'DataCollector';

    $contributor = new ResourceContributor;
    $contributor->setRelation('affiliations', collect());
    $contributor->setRelation('contributorTypes', collect([$storedType]));

    $data = $this->mapper->buildPersonContributorData($contributor, $person, 'ProjectLeader');

    expect($data)->toMatchArray([
        'name' => 'Miller, Alex',
        'nameType' => 'Personal',
        'contributorType' => 'ProjectLeader',
    ]);
});

it('falls back to the first stored contributor type when no explicit type is passed', function (): void {
    $institution = Institution::factory()->make([
        'name' => 'GFZ Potsdam',
        'name_identifier' => 'https://ror.org/04z8jg394',
        'name_identifier_scheme' => 'ROR',
    ]);

    $storedType = new ContributorType;
    $storedType->slug = 'HostingInstitution';

    $contributor = new ResourceContributor;
    $contributor->setRelation('affiliations', collect());
    $contributor->setRelation('contributorTypes', collect([$storedType]));

    $data = $this->mapper->buildInstitutionContributorData($contributor, $institution);

    expect($data)->toMatchArray([
        'name' => 'GFZ Potsdam',
        'nameType' => 'Organizational',
        'contributorType' => 'HostingInstitution',
        'nameIdentifiers' => [[
            'nameIdentifier' => 'https://ror.org/04z8jg394',
            'nameIdentifierScheme' => 'ROR',
            'schemeUri' => 'https://ror.org/',
        ]],
    ]);
});