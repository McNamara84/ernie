<?php

declare(strict_types=1);

use App\Models\LandingPage;
use App\Models\Resource;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ResourceTestDataSeeder;

uses()->group('seeders', 'test-data');

beforeEach(function () {
    // Run base seeders first (lookup tables like Rights, ResourceTypes, etc.)
    $this->seed(DatabaseSeeder::class);
    // Then run the test data seeder
    $this->seed(ResourceTestDataSeeder::class);
});

describe('ResourceTestDataSeeder', function () {
    test('creates 27 test resources', function () {
        $testResources = Resource::where('doi', 'LIKE', '10.5880/testdata.%')->count();

        expect($testResources)->toBe(27);
    });

    test('all resources have a title', function () {
        $resourcesWithoutTitle = Resource::where('doi', 'LIKE', '10.5880/testdata.%')
            ->whereDoesntHave('titles')
            ->count();

        expect($resourcesWithoutTitle)->toBe(0);
    });

    test('all resources have an abstract (mandatory field)', function () {
        $resourcesWithoutAbstract = Resource::where('doi', 'LIKE', '10.5880/testdata.%')
            ->whereDoesntHave('descriptions', function ($query) {
                $query->whereHas('descriptionType', fn ($q) => $q->where('slug', 'Abstract'));
            })
            ->count();

        expect($resourcesWithoutAbstract)->toBe(0);
    });

    test('all resources have at least one creator', function () {
        $resourcesWithoutCreator = Resource::where('doi', 'LIKE', '10.5880/testdata.%')
            ->whereDoesntHave('creators')
            ->count();

        expect($resourcesWithoutCreator)->toBe(0);
    });

    test('creates published landing pages for all resources', function () {
        $resourceCount = Resource::where('doi', 'LIKE', '10.5880/testdata.%')->count();
        $landingPageCount = LandingPage::whereHas('resource', function ($query) {
            $query->where('doi', 'LIKE', '10.5880/testdata.%');
        })->where('is_published', true)->count();

        expect($landingPageCount)->toBe($resourceCount);
    });
});

describe('Scenario: Mandatory Fields Only', function () {
    test('has correct structure', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Mandatory Fields Only%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->creators)->toHaveCount(1);
        expect($resource->descriptions)->toHaveCount(1);
        expect($resource->rights)->toHaveCount(1);
        expect($resource->geoLocations)->toBeEmpty();
    });

    test('has contact person with email', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Mandatory Fields Only%'))
            ->first();

        $contactCreator = $resource->creators->first(fn ($c) => $c->is_contact);

        expect($contactCreator)->not->toBeNull();
        expect($contactCreator->email)->not->toBeNull();
    });

    test('has CC-BY-4.0 license', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Mandatory Fields Only%'))
            ->first();

        $license = $resource->rights->first();

        expect($license)->not->toBeNull();
        expect($license->identifier)->toBe('CC-BY-4.0');
    });
});

describe('Scenario: Fully Populated Resource', function () {
    test('has all metadata fields populated', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Fully Populated%'))
            ->first();

        expect($resource)->not->toBeNull();
        // 3 creators: 1 default contact + 2 scenario creators
        expect($resource->creators)->toHaveCount(3);
        expect($resource->contributors)->toHaveCount(1);
        expect($resource->descriptions)->not->toBeEmpty();
        expect($resource->subjects)->not->toBeEmpty();
        expect($resource->geoLocations)->not->toBeEmpty();
        expect($resource->relatedIdentifiers)->not->toBeEmpty();
        expect($resource->fundingReferences)->not->toBeEmpty();
        expect($resource->rights)->not->toBeEmpty();
    });

    test('has creators with ORCID and affiliations', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Fully Populated%'))
            ->first();

        $creatorWithOrcid = $resource->creators->first(function ($creator) {
            $person = $creator->creatorable;

            return $person && $person->name_identifier !== null;
        });

        expect($creatorWithOrcid)->not->toBeNull();
        expect($creatorWithOrcid->affiliations)->not->toBeEmpty();
    });
});

describe('Scenario: Many Creators with ORCIDs', function () {
    test('has 8 creators all with ORCIDs', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Creators All with ORCID%'))
            ->first();

        expect($resource)->not->toBeNull();
        // 9 creators: 1 default contact + 8 scenario creators with ORCID
        expect($resource->creators)->toHaveCount(9);

        $creatorsWithOrcid = $resource->creators->filter(function ($creator) {
            $person = $creator->creatorable;

            return $person && $person->name_identifier !== null;
        });

        // 8 creators have ORCID (default contact doesn't have one)
        expect($creatorsWithOrcid)->toHaveCount(8);
    });
});

describe('Scenario: GeoLocations', function () {
    test('points only scenario has 8 point locations', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Points Only%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->geoLocations)->toHaveCount(8);

        $pointsWithCoords = $resource->geoLocations->filter(fn ($g) => $g->point_longitude !== null && $g->point_latitude !== null);

        expect($pointsWithCoords)->toHaveCount(8);
    });

    test('bounding boxes scenario has 3 bounding boxes', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Bounding Boxes%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->geoLocations)->toHaveCount(3);

        $boxes = $resource->geoLocations->filter(fn ($g) => $g->west_bound_longitude !== null && $g->east_bound_longitude !== null);

        expect($boxes)->toHaveCount(3);
    });

    test('polygons scenario has 2 polygons', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Polygons%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->geoLocations)->toHaveCount(2);

        $polygons = $resource->geoLocations->filter(fn ($g) => $g->polygon_points !== null);

        expect($polygons)->toHaveCount(2);
    });

    test('no geo-locations scenario has empty geo_locations', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%No GeoLocations%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->geoLocations)->toBeEmpty();
    });

    test('geo-location coordinates are numeric values', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Points Only%'))
            ->first();

        $geoLocation = $resource->geoLocations->first();

        // Database stores as decimal, so check if numeric
        expect(is_numeric($geoLocation->point_longitude))->toBeTrue();
        expect(is_numeric($geoLocation->point_latitude))->toBeTrue();
        expect((float) $geoLocation->point_longitude)->toBeGreaterThan(-180);
        expect((float) $geoLocation->point_latitude)->toBeGreaterThan(-90);
    });
});

describe('Scenario: Related Identifiers', function () {
    test('has 6 related identifiers with real DOIs', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Related Identifiers%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->relatedIdentifiers)->toHaveCount(6);

        // Verify real DOIs are used
        $realDois = [
            '10.5880/igets.su.l1.001',
            '10.1007/978-3-642-20338-1_37',
            '10.1016/j.jog.2009.09.009',
            '10.1016/j.jog.2009.09.020',
            '10.1785/0120100217',
        ];

        foreach ($realDois as $doi) {
            $found = $resource->relatedIdentifiers->first(fn ($r) => $r->identifier === $doi);
            expect($found)->not->toBeNull("DOI {$doi} should be present");
        }
    });

    test('related identifiers have correct relation types', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Related Identifiers%'))
            ->first();

        $relationTypes = $resource->relatedIdentifiers->map(fn ($r) => $r->relationType->slug)->toArray();

        expect($relationTypes)->toContain('Cites');
        expect($relationTypes)->toContain('IsSupplementTo');
        expect($relationTypes)->toContain('References');
    });
});

describe('Scenario: Funding References', function () {
    test('has 4 funding references with ROR identifiers', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Funding References%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->fundingReferences)->toHaveCount(4);

        // Check for ROR identifiers
        $withRor = $resource->fundingReferences->filter(fn ($f) => str_contains($f->funder_identifier ?? '', 'ror.org'));

        expect($withRor)->toHaveCount(4);
    });
});

describe('Scenario: Keywords/Subjects', function () {
    test('many keywords scenario has 15 free-text keywords', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Free-Text Keywords%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->subjects)->toHaveCount(15);
    });

    test('subjects use correct column name (value)', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Free-Text Keywords%'))
            ->first();

        $firstSubject = $resource->subjects->first();

        expect($firstSubject)->not->toBeNull();
        expect($firstSubject->value)->not->toBeNull();
    });
});

describe('Scenario: Licenses', function () {
    test('single license scenario has exactly one CC-BY-4.0', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Single License%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->rights)->toHaveCount(1);
        expect($resource->rights->first()->identifier)->toBe('CC-BY-4.0');
    });

    test('multiple licenses scenario has 3 licenses', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Multiple Licenses%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->rights)->toHaveCount(3);
    });
});

describe('Scenario: Contact Persons', function () {
    test('has multiple contact persons with email and website', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Contact Persons%'))
            ->first();

        expect($resource)->not->toBeNull();

        $contactCreators = $resource->creators->filter(fn ($c) => $c->is_contact);

        expect($contactCreators)->not->toBeEmpty();

        $creatorWithEmail = $contactCreators->first(fn ($c) => $c->email !== null);
        expect($creatorWithEmail)->not->toBeNull();

        $creatorWithWebsite = $contactCreators->first(fn ($c) => $c->website !== null);
        expect($creatorWithWebsite)->not->toBeNull();
    });
});

describe('Scenario: Contributors', function () {
    test('many contributors scenario has 10 contributors', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Many Contributors%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->contributors)->toHaveCount(10);
    });

    test('contributors with ROR scenario has ROR-linked affiliations', function () {
        $resource = Resource::whereHas('titles', fn ($q) => $q->where('value', 'LIKE', '%Contributors with ROR%'))
            ->first();

        expect($resource)->not->toBeNull();
        expect($resource->contributors)->not->toBeEmpty();

        // Check for affiliations with ROR
        $contributorWithRor = $resource->contributors->first(function ($contributor) {
            return $contributor->affiliations->first(fn ($a) => str_contains($a->identifier ?? '', 'ror.org')) !== null;
        });

        expect($contributorWithRor)->not->toBeNull();
    });
});

describe('Landing Pages', function () {
    test('all landing pages have unique slugs', function () {
        $landingPages = LandingPage::whereHas('resource', function ($query) {
            $query->where('doi', 'LIKE', '10.5880/testdata.%');
        })->get();

        $slugs = $landingPages->pluck('slug')->toArray();
        $uniqueSlugs = array_unique($slugs);

        expect(count($slugs))->toBe(count($uniqueSlugs));
    });

    test('landing pages are accessible by slug', function () {
        // Find any landing page from our test resources
        $landingPage = LandingPage::whereHas('resource', function ($query) {
            $query->where('doi', 'LIKE', '10.5880/testdata.%');
        })->first();

        expect($landingPage)->not->toBeNull();
        expect($landingPage->is_published)->toBeTrue();
        expect($landingPage->slug)->not->toBeNull();
    });

    test('landing pages have published_at timestamp', function () {
        $landingPages = LandingPage::whereHas('resource', function ($query) {
            $query->where('doi', 'LIKE', '10.5880/testdata.%');
        })->get();

        foreach ($landingPages as $landingPage) {
            expect($landingPage->published_at)->not->toBeNull();
        }
    });
});
