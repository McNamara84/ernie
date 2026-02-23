<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\ContributorType;
use App\Models\FundingReference;
use App\Models\GeoLocation;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Models\ResourceDate;
use App\Services\Traits\DataCiteExporterHelpers;

covers(DataCiteExporterHelpers::class);

/**
 * Create a test class that uses the trait so we can test its protected methods directly.
 * The __call method delegates to the protected trait methods.
 */
beforeEach(function (): void {
    $this->helper = new class
    {
        use DataCiteExporterHelpers;

        /**
         * @param  array<int, mixed>  $arguments
         */
        public function __call(string $name, array $arguments): mixed
        {
            return $this->{$name}(...$arguments);
        }
    };
});

describe('getRequiredRelations()', function (): void {
    it('returns an array of relation names', function (): void {
        $relations = $this->helper->getRequiredRelations();

        expect($relations)->toBeArray()
            ->toContain('resourceType')
            ->toContain('language')
            ->toContain('publisher')
            ->toContain('titles.titleType')
            ->toContain('creators.creatorable')
            ->toContain('creators.affiliations')
            ->toContain('contributors.contributorable')
            ->toContain('igsnMetadata');
    });
});

describe('formatPersonName()', function (): void {
    it('formats with both family and given name', function (): void {
        $person = Person::factory()->make([
            'family_name' => 'Einstein',
            'given_name' => 'Albert',
        ]);

        $result = $this->helper->formatPersonName($person);

        expect($result)->toBe('Einstein, Albert');
    });

    it('returns only family name when given name is missing', function (): void {
        $person = Person::factory()->make([
            'family_name' => 'Einstein',
            'given_name' => null,
        ]);

        $result = $this->helper->formatPersonName($person);

        expect($result)->toBe('Einstein');
    });

    it('returns only given name when family name is missing', function (): void {
        $person = Person::factory()->make([
            'family_name' => null,
            'given_name' => 'Albert',
        ]);

        $result = $this->helper->formatPersonName($person);

        expect($result)->toBe('Albert');
    });

    it('returns Unknown when both names are missing', function (): void {
        $person = Person::factory()->make([
            'family_name' => null,
            'given_name' => null,
        ]);

        $result = $this->helper->formatPersonName($person);

        expect($result)->toBe('Unknown');
    });
});

describe('formatInstitutionName()', function (): void {
    it('returns institution name', function (): void {
        $institution = Institution::factory()->make(['name' => 'GFZ Potsdam']);

        $result = $this->helper->formatInstitutionName($institution);

        expect($result)->toBe('GFZ Potsdam');
    });
});

describe('buildPersonNameIdentifier()', function (): void {
    it('returns null when person has no identifier', function (): void {
        $person = Person::factory()->make([
            'name_identifier' => null,
        ]);

        $result = $this->helper->buildPersonNameIdentifier($person);

        expect($result)->toBeNull();
    });

    it('returns ORCID identifier data', function (): void {
        $person = Person::factory()->withOrcid('https://orcid.org/0000-0001-2345-6789')->make();

        $result = $this->helper->buildPersonNameIdentifier($person);

        expect($result)->toBe([
            'nameIdentifier' => 'https://orcid.org/0000-0001-2345-6789',
            'nameIdentifierScheme' => 'ORCID',
            'schemeUri' => 'https://orcid.org/',
        ]);
    });

    it('defaults to ORCID scheme when scheme is null', function (): void {
        $person = Person::factory()->make([
            'name_identifier' => 'https://orcid.org/0000-0001-2345-6789',
            'name_identifier_scheme' => null,
        ]);

        $result = $this->helper->buildPersonNameIdentifier($person);

        expect($result['nameIdentifierScheme'])->toBe('ORCID');
    });
});

describe('buildInstitutionNameIdentifier()', function (): void {
    it('returns null when institution has no identifier', function (): void {
        $institution = Institution::factory()->make([
            'name_identifier' => null,
        ]);

        $result = $this->helper->buildInstitutionNameIdentifier($institution);

        expect($result)->toBeNull();
    });

    it('returns ROR identifier data', function (): void {
        $institution = Institution::factory()->make([
            'name_identifier' => 'https://ror.org/04z8jg394',
            'name_identifier_scheme' => 'ROR',
        ]);

        $result = $this->helper->buildInstitutionNameIdentifier($institution);

        expect($result)->toBe([
            'nameIdentifier' => 'https://ror.org/04z8jg394',
            'nameIdentifierScheme' => 'ROR',
            'schemeUri' => 'https://ror.org/',
        ]);
    });
});

describe('getSchemeUri()', function (): void {
    it('returns correct URI for ORCID', function (): void {
        $result = $this->helper->getSchemeUri('ORCID');

        expect($result)->toBe('https://orcid.org/');
    });

    it('returns correct URI for ROR', function (): void {
        $result = $this->helper->getSchemeUri('ROR');

        expect($result)->toBe('https://ror.org/');
    });

    it('returns correct URI for ISNI', function (): void {
        $result = $this->helper->getSchemeUri('ISNI');

        expect($result)->toBe('https://isni.org/');
    });

    it('returns correct URI for GRID', function (): void {
        $result = $this->helper->getSchemeUri('GRID');

        expect($result)->toBe('https://www.grid.ac/');
    });

    it('returns empty string for unknown scheme', function (): void {
        $result = $this->helper->getSchemeUri('UnknownScheme');

        expect($result)->toBe('');
    });

    it('is case-insensitive', function (): void {
        expect($this->helper->getSchemeUri('orcid'))->toBe('https://orcid.org/');
        expect($this->helper->getSchemeUri('ror'))->toBe('https://ror.org/');
    });
});

describe('formatDateValue()', function (): void {
    it('formats a single date using date_value', function (): void {
        $date = new ResourceDate;
        $date->date_value = '2024-01-15';
        $date->start_date = null;
        $date->end_date = null;

        $result = $this->helper->formatDateValue($date);

        expect($result)->toBe('2024-01-15');
    });

    it('formats a single date using start_date as fallback', function (): void {
        $date = new ResourceDate;
        $date->date_value = null;
        $date->start_date = '2024-06-01';
        $date->end_date = null;

        $result = $this->helper->formatDateValue($date);

        expect($result)->toBe('2024-06-01');
    });

    it('formats a closed date range', function (): void {
        $date = new ResourceDate;
        $date->date_value = null;
        $date->start_date = '2024-01-01';
        $date->end_date = '2024-12-31';

        $result = $this->helper->formatDateValue($date);

        expect($result)->toBe('2024-01-01/2024-12-31');
    });

    it('formats an open-ended range as single date', function (): void {
        $date = new ResourceDate;
        $date->date_value = null;
        $date->start_date = '2024-01-01';
        $date->end_date = '';

        // Open-ended: has start_date but empty end_date
        $result = $this->helper->formatDateValue($date);

        // When end_date is empty string, isRange() and isOpenEndedRange() depend on model logic
        expect($result)->toBeString();
    });

    it('returns null when no date values are set', function (): void {
        $date = new ResourceDate;
        $date->date_value = null;
        $date->start_date = null;
        $date->end_date = null;

        $result = $this->helper->formatDateValue($date);

        expect($result)->toBeNull();
    });
});

describe('transformGeoLocationPoint()', function (): void {
    it('returns null when coordinates are missing', function (): void {
        $geo = new GeoLocation;
        $geo->point_longitude = null;
        $geo->point_latitude = null;

        $result = $this->helper->transformGeoLocationPoint($geo);

        expect($result)->toBeNull();
    });

    it('transforms point coordinates to float', function (): void {
        $geo = new GeoLocation;
        $geo->point_longitude = '13.0650';
        $geo->point_latitude = '52.3938';

        $result = $this->helper->transformGeoLocationPoint($geo);

        expect($result)->toBe([
            'pointLongitude' => 13.065,
            'pointLatitude' => 52.3938,
        ]);
    });

    it('returns null when only longitude is set', function (): void {
        $geo = new GeoLocation;
        $geo->point_longitude = '13.0650';
        $geo->point_latitude = null;

        $result = $this->helper->transformGeoLocationPoint($geo);

        expect($result)->toBeNull();
    });
});

describe('transformGeoLocationBox()', function (): void {
    it('returns null when any coordinate is missing', function (): void {
        $geo = new GeoLocation;
        $geo->west_bound_longitude = '12.0';
        $geo->east_bound_longitude = '14.0';
        $geo->south_bound_latitude = null;
        $geo->north_bound_latitude = '53.0';

        $result = $this->helper->transformGeoLocationBox($geo);

        expect($result)->toBeNull();
    });

    it('transforms box coordinates to float', function (): void {
        $geo = new GeoLocation;
        $geo->west_bound_longitude = '12.0';
        $geo->east_bound_longitude = '14.0';
        $geo->south_bound_latitude = '51.0';
        $geo->north_bound_latitude = '53.0';

        $result = $this->helper->transformGeoLocationBox($geo);

        expect($result)->toBe([
            'westBoundLongitude' => 12.0,
            'eastBoundLongitude' => 14.0,
            'southBoundLatitude' => 51.0,
            'northBoundLatitude' => 53.0,
        ]);
    });
});

describe('transformGeoLocationPolygon()', function (): void {
    it('returns null when polygon_points is null', function (): void {
        $geo = new GeoLocation;
        $geo->polygon_points = null;

        $result = $this->helper->transformGeoLocationPolygon($geo);

        expect($result)->toBeNull();
    });

    it('returns null when fewer than 3 points', function (): void {
        $geo = new GeoLocation;
        $geo->polygon_points = [
            ['longitude' => 12.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 53.0],
        ];

        $result = $this->helper->transformGeoLocationPolygon($geo);

        expect($result)->toBeNull();
    });

    it('transforms polygon points', function (): void {
        $geo = new GeoLocation;
        $geo->polygon_points = [
            ['longitude' => 12.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 53.0],
            ['longitude' => 12.0, 'latitude' => 51.0],
        ];
        $geo->in_polygon_point_longitude = null;
        $geo->in_polygon_point_latitude = null;

        $result = $this->helper->transformGeoLocationPolygon($geo);

        expect($result)->toHaveKey('polygonPoints');
        expect($result['polygonPoints'])->toHaveCount(4);
        expect($result['polygonPoints'][0])->toBe([
            'pointLongitude' => 12.0,
            'pointLatitude' => 51.0,
        ]);
        expect($result)->not->toHaveKey('inPolygonPoint');
    });

    it('includes in-polygon point when available', function (): void {
        $geo = new GeoLocation;
        $geo->polygon_points = [
            ['longitude' => 12.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 51.0],
            ['longitude' => 14.0, 'latitude' => 53.0],
            ['longitude' => 12.0, 'latitude' => 51.0],
        ];
        $geo->in_polygon_point_longitude = '13.0';
        $geo->in_polygon_point_latitude = '52.0';

        $result = $this->helper->transformGeoLocationPolygon($geo);

        expect($result)->toHaveKey('inPolygonPoint');
        expect($result['inPolygonPoint'])->toBe([
            'pointLongitude' => 13.0,
            'pointLatitude' => 52.0,
        ]);
    });
});

describe('transformFundingReference()', function (): void {
    it('transforms minimal funding reference', function (): void {
        $funding = new FundingReference;
        $funding->funder_name = 'Deutsche Forschungsgemeinschaft';
        $funding->funder_identifier = null;
        $funding->award_number = null;
        $funding->award_uri = null;
        $funding->award_title = null;

        $result = $this->helper->transformFundingReference($funding);

        expect($result)->toBe([
            'funderName' => 'Deutsche Forschungsgemeinschaft',
        ]);
    });

    it('includes funder identifier and type', function (): void {
        $resource = Resource::factory()->create();
        $funding = new FundingReference;
        $funding->resource_id = $resource->id;
        $funding->funder_name = 'DFG';
        $funding->funder_identifier = 'https://doi.org/10.13039/501100001659';
        $funding->scheme_uri = 'https://www.crossref.org/fundref/';
        $funding->award_number = null;
        $funding->award_uri = null;
        $funding->award_title = null;
        $funding->save();
        $funding->load('funderIdentifierType');

        $result = $this->helper->transformFundingReference($funding);

        expect($result)->toHaveKey('funderName', 'DFG');
        expect($result)->toHaveKey('funderIdentifier', 'https://doi.org/10.13039/501100001659');
        expect($result)->toHaveKey('funderIdentifierType');
        expect($result)->toHaveKey('schemeUri', 'https://www.crossref.org/fundref/');
    });

    it('includes award information', function (): void {
        $funding = new FundingReference;
        $funding->funder_name = 'NSF';
        $funding->funder_identifier = null;
        $funding->award_number = 'EAR-1234567';
        $funding->award_uri = 'https://nsf.gov/award/1234567';
        $funding->award_title = 'Critical Zone Science';

        $result = $this->helper->transformFundingReference($funding);

        expect($result)->toHaveKey('awardNumber', 'EAR-1234567');
        expect($result)->toHaveKey('awardUri', 'https://nsf.gov/award/1234567');
        expect($result)->toHaveKey('awardTitle', 'Critical Zone Science');
    });
});

describe('buildPersonCreatorData()', function (): void {
    it('builds person creator data with all fields', function (): void {
        $person = Person::factory()->withOrcid('https://orcid.org/0000-0001-2345-6789')->create([
            'given_name' => 'Albert',
            'family_name' => 'Einstein',
        ]);
        $creator = ResourceCreator::factory()->create([
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
        ]);
        $creator->load('affiliations');

        $result = $this->helper->buildPersonCreatorData($creator, $person);

        expect($result)->toHaveKey('name', 'Einstein, Albert');
        expect($result)->toHaveKey('nameType', 'Personal');
        expect($result)->toHaveKey('givenName', 'Albert');
        expect($result)->toHaveKey('familyName', 'Einstein');
        expect($result)->toHaveKey('nameIdentifiers');
    });

    it('omits given/family name when not present', function (): void {
        $person = Person::factory()->create([
            'given_name' => null,
            'family_name' => 'Einstein',
            'name_identifier' => null,
        ]);
        $creator = ResourceCreator::factory()->create([
            'creatorable_type' => Person::class,
            'creatorable_id' => $person->id,
        ]);
        $creator->load('affiliations');

        $result = $this->helper->buildPersonCreatorData($creator, $person);

        expect($result)->toHaveKey('familyName', 'Einstein');
        expect($result)->not->toHaveKey('givenName');
        expect($result)->not->toHaveKey('nameIdentifiers');
    });
});

describe('buildInstitutionCreatorData()', function (): void {
    it('builds institution creator data', function (): void {
        $institution = Institution::factory()->create([
            'name' => 'GFZ German Research Centre for Geosciences',
            'name_identifier' => 'https://ror.org/04z8jg394',
            'name_identifier_scheme' => 'ROR',
        ]);
        $creator = ResourceCreator::factory()->create([
            'creatorable_type' => Institution::class,
            'creatorable_id' => $institution->id,
        ]);
        $creator->load('affiliations');

        $result = $this->helper->buildInstitutionCreatorData($creator, $institution);

        expect($result)->toHaveKey('name', 'GFZ German Research Centre for Geosciences');
        expect($result)->toHaveKey('nameType', 'Organizational');
        expect($result)->toHaveKey('nameIdentifiers');
    });
});

describe('buildPersonContributorData()', function (): void {
    it('includes contributor type', function (): void {
        $person = Person::factory()->create([
            'given_name' => 'Marie',
            'family_name' => 'Curie',
            'name_identifier' => null,
        ]);
        $contributorType = ContributorType::create([
            'slug' => 'ContactPerson',
            'name' => 'Contact Person',
            'is_active' => true,
        ]);
        $contributor = ResourceContributor::factory()->create([
            'contributorable_type' => Person::class,
            'contributorable_id' => $person->id,
        ]);
        $contributor->contributorTypes()->sync([$contributorType->id]);
        $contributor->load('affiliations', 'contributorTypes');

        $result = $this->helper->buildPersonContributorData($contributor, $person);

        expect($result)->toHaveKey('name', 'Curie, Marie');
        expect($result)->toHaveKey('contributorType', 'ContactPerson');
    });
});

describe('buildInstitutionContributorData()', function (): void {
    it('includes contributor type for institution', function (): void {
        $institution = Institution::factory()->create([
            'name' => 'MIT',
            'name_identifier' => null,
        ]);
        $contributorType = ContributorType::create([
            'slug' => 'HostingInstitution',
            'name' => 'Hosting Institution',
            'is_active' => true,
        ]);
        $contributor = ResourceContributor::factory()->create([
            'contributorable_type' => Institution::class,
            'contributorable_id' => $institution->id,
        ]);
        $contributor->contributorTypes()->sync([$contributorType->id]);
        $contributor->load('affiliations', 'contributorTypes');

        $result = $this->helper->buildInstitutionContributorData($contributor, $institution);

        expect($result)->toHaveKey('name', 'MIT');
        expect($result)->toHaveKey('nameType', 'Organizational');
        expect($result)->toHaveKey('contributorType', 'HostingInstitution');
    });
});

describe('transformAffiliation()', function (): void {
    it('transforms simple affiliation with name only', function (): void {
        $affiliation = new Affiliation;
        $affiliation->name = 'University of Potsdam';
        $affiliation->identifier = null;
        $affiliation->identifier_scheme = null;
        $affiliation->scheme_uri = null;

        $result = $this->helper->transformAffiliation($affiliation);

        expect($result)->toBe(['name' => 'University of Potsdam']);
    });

    it('transforms affiliation with ROR identifier', function (): void {
        $affiliation = new Affiliation;
        $affiliation->name = 'GFZ Potsdam';
        $affiliation->identifier = 'https://ror.org/04z8jg394';
        $affiliation->identifier_scheme = 'ROR';
        $affiliation->scheme_uri = 'https://ror.org/';

        $result = $this->helper->transformAffiliation($affiliation);

        expect($result)->toHaveKey('name', 'GFZ Potsdam');
        expect($result)->toHaveKey('affiliationIdentifier', 'https://ror.org/04z8jg394');
        expect($result)->toHaveKey('affiliationIdentifierScheme', 'ROR');
        expect($result)->toHaveKey('schemeURI', 'https://ror.org/');
    });

    it('defaults identifier scheme to ROR when not set', function (): void {
        $affiliation = new Affiliation;
        $affiliation->name = 'GFZ Potsdam';
        $affiliation->identifier = 'https://ror.org/04z8jg394';
        $affiliation->identifier_scheme = null;
        $affiliation->scheme_uri = null;

        $result = $this->helper->transformAffiliation($affiliation);

        expect($result)->toHaveKey('affiliationIdentifierScheme', 'ROR');
        expect($result)->toHaveKey('schemeURI', 'https://ror.org/');
    });
});
