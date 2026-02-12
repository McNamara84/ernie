<?php

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceCreator;
use App\Services\Entities\AffiliationService;
use App\Services\RorLookupService;

describe('AffiliationService - Affiliation Parsing', function () {
    test('parses affiliation with name and rorId', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => 'University of Potsdam', 'rorId' => 'https://ror.org/03bnmw459'],
            ],
        ]);

        expect($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('University of Potsdam')
            ->and($result[0]['identifier'])->toBe('https://ror.org/03bnmw459')
            ->and($result[0]['identifier_scheme'])->toBe('ROR')
            ->and($result[0]['scheme_uri'])->toBe('https://ror.org/');
    });

    test('parses affiliation with name only (no ROR)', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => 'Some University', 'rorId' => null],
            ],
        ]);

        expect($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('Some University')
            ->and($result[0]['identifier'])->toBeNull()
            ->and($result[0]['identifier_scheme'])->toBeNull()
            ->and($result[0]['scheme_uri'])->toBeNull();
    });

    test('detects ROR URL in value field and moves it to identifier', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => 'https://ror.org/04z8jg394', 'rorId' => null],
            ],
        ]);

        expect($result)->toHaveCount(1)
            ->and($result[0]['identifier'])->toBe('https://ror.org/04z8jg394')
            ->and($result[0]['identifier_scheme'])->toBe('ROR')
            ->and($result[0]['scheme_uri'])->toBe('https://ror.org/')
            // Name should NOT be the ROR URL
            ->and($result[0]['name'])->not->toContain('ror.org');
    });

    test('handles affiliation with only rorId and empty value', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => '', 'rorId' => 'https://ror.org/04z8jg394'],
            ],
        ]);

        // Should still produce a record even with empty name
        expect($result)->toHaveCount(1)
            ->and($result[0]['identifier'])->toBe('https://ror.org/04z8jg394')
            ->and($result[0]['identifier_scheme'])->toBe('ROR')
            ->and($result[0]['scheme_uri'])->toBe('https://ror.org/');
    });

    test('filters out entirely empty affiliations', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => '', 'rorId' => null],
                ['value' => '', 'rorId' => ''],
                ['value' => '   ', 'rorId' => null],
            ],
        ]);

        expect($result)->toBeEmpty();
    });

    test('handles non-ROR affiliations correctly', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                ['value' => 'https://example.com/institution', 'rorId' => null],
            ],
        ]);

        expect($result)->toHaveCount(1)
            ->and($result[0]['name'])->toBe('https://example.com/institution')
            ->and($result[0]['identifier'])->toBeNull()
            ->and($result[0]['identifier_scheme'])->toBeNull();
    });

    test('skips invalid affiliation data', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([
            'affiliations' => [
                'not-an-array',
                null,
                42,
            ],
        ]);

        expect($result)->toBeEmpty();
    });

    test('returns empty array when no affiliations key present', function () {
        $rorLookup = app(RorLookupService::class);
        $service = new AffiliationService($rorLookup);

        $result = $service->parseAffiliationsFromData([]);

        expect($result)->toBeEmpty();
    });
});

describe('AffiliationService - Database Integration', function () {
    test('syncForCreator creates affiliations with scheme_uri', function () {
        $resource = Resource::factory()->create();
        $person = Person::factory()->create();
        $creator = ResourceCreator::create([
            'resource_id' => $resource->id,
            'creatorable_id' => $person->id,
            'creatorable_type' => Person::class,
            'position' => 1,
        ]);

        $service = app(AffiliationService::class);
        $service->syncForCreator($creator, [
            'affiliations' => [
                ['value' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394'],
            ],
        ]);

        $affiliation = $creator->affiliations()->first();

        expect($affiliation)->not->toBeNull()
            ->and($affiliation->name)->toBe('GFZ Potsdam')
            ->and($affiliation->identifier)->toBe('https://ror.org/04z8jg394')
            ->and($affiliation->identifier_scheme)->toBe('ROR')
            ->and($affiliation->scheme_uri)->toBe('https://ror.org/');
    });
});
