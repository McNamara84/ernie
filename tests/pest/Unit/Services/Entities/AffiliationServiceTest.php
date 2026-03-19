<?php

declare(strict_types=1);

use App\Models\Affiliation;
use App\Models\Person;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Entities\AffiliationService;
use App\Services\RorLookupService;

covers(AffiliationService::class);

describe('AffiliationService', function () {
    beforeEach(function () {
        $this->rorLookup = Mockery::mock(RorLookupService::class);
        $this->service = new AffiliationService($this->rorLookup);
    });

    describe('parseAffiliationsFromData', function () {
        it('returns empty array when no affiliations key exists', function () {
            $result = $this->service->parseAffiliationsFromData([]);

            expect($result)->toBe([]);
        });

        it('returns empty array for empty affiliations', function () {
            $result = $this->service->parseAffiliationsFromData(['affiliations' => []]);

            expect($result)->toBe([]);
        });

        it('returns empty array when affiliations is not an array', function () {
            $result = $this->service->parseAffiliationsFromData(['affiliations' => 'invalid']);

            expect($result)->toBe([]);
        });

        it('parses affiliation with name only', function () {
            $this->rorLookup->shouldReceive('canonicalise')->never();
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => 'GFZ Potsdam'],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('GFZ Potsdam');
            expect($result[0]['identifier'])->toBeNull();
            expect($result[0]['identifier_scheme'])->toBeNull();
            expect($result[0]['scheme_uri'])->toBeNull();
        });

        it('parses affiliation with name and ROR ID', function () {
            $this->rorLookup->shouldReceive('canonicalise')
                ->with('https://ror.org/04z8jg394')
                ->andReturn('https://ror.org/04z8jg394');
            $this->rorLookup->shouldReceive('isRorUrl')->never();

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    [
                        'value' => 'GFZ Potsdam',
                        'rorId' => 'https://ror.org/04z8jg394',
                    ],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('GFZ Potsdam');
            expect($result[0]['identifier'])->toBe('https://ror.org/04z8jg394');
            expect($result[0]['identifier_scheme'])->toBe('ROR');
            expect($result[0]['scheme_uri'])->toBe('https://ror.org/');
        });

        it('skips entries that are not arrays', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    'not-an-array',
                    123,
                    null,
                ],
            ]);

            expect($result)->toBe([]);
        });

        it('skips empty entries with no name and no identifier', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => ''],
                    ['value' => '   '],
                ],
            ]);

            expect($result)->toBe([]);
        });

        it('handles ROR URL accidentally placed in name field', function () {
            $this->rorLookup->shouldReceive('isRorUrl')
                ->with('https://ror.org/04z8jg394')
                ->andReturn(true);
            $this->rorLookup->shouldReceive('canonicalise')
                ->with('https://ror.org/04z8jg394')
                ->andReturn('https://ror.org/04z8jg394');
            $this->rorLookup->shouldReceive('resolve')
                ->with('https://ror.org/04z8jg394')
                ->andReturn('GFZ German Research Centre for Geosciences');

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => 'https://ror.org/04z8jg394'],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('GFZ German Research Centre for Geosciences');
            expect($result[0]['identifier'])->toBe('https://ror.org/04z8jg394');
        });

        it('resolves name from ROR when only identifier is provided', function () {
            $this->rorLookup->shouldReceive('canonicalise')
                ->with('https://ror.org/04z8jg394')
                ->andReturn('https://ror.org/04z8jg394');
            $this->rorLookup->shouldReceive('resolve')
                ->with('https://ror.org/04z8jg394')
                ->andReturn('GFZ Potsdam');
            $this->rorLookup->shouldReceive('isRorUrl')->never();

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => '', 'rorId' => 'https://ror.org/04z8jg394'],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('GFZ Potsdam');
        });

        it('handles empty ROR ID string', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => 'Test Uni', 'rorId' => ''],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['identifier'])->toBeNull();
        });

        it('handles non-string ROR ID', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => 'Test Uni', 'rorId' => 123],
                ],
            ]);

            expect($result)->toHaveCount(1);
            expect($result[0]['identifier'])->toBeNull();
        });

        it('parses multiple affiliations', function () {
            $this->rorLookup->shouldReceive('canonicalise')
                ->with('https://ror.org/abc123')
                ->andReturn('https://ror.org/abc123');
            $this->rorLookup->shouldReceive('isRorUrl')
                ->with('MIT')
                ->andReturn(false);

            $result = $this->service->parseAffiliationsFromData([
                'affiliations' => [
                    ['value' => 'GFZ', 'rorId' => 'https://ror.org/abc123'],
                    ['value' => 'MIT'],
                ],
            ]);

            expect($result)->toHaveCount(2);
            expect($result[0]['identifier'])->toBe('https://ror.org/abc123');
            expect($result[1]['identifier'])->toBeNull();
        });
    });

    describe('syncForCreator', function () {
        it('creates affiliations for a creator', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $creator = ResourceCreator::factory()->forPerson()->create();

            $this->service->syncForCreator($creator, [
                'affiliations' => [
                    ['value' => 'GFZ Potsdam'],
                ],
            ]);

            expect($creator->affiliations()->count())->toBe(1);
            expect($creator->affiliations()->first()->name)->toBe('GFZ Potsdam');
        });

        it('does nothing when no affiliations provided', function () {
            $creator = ResourceCreator::factory()->forPerson()->create();

            $this->service->syncForCreator($creator, []);

            expect($creator->affiliations()->count())->toBe(0);
        });
    });

    describe('syncForContributor', function () {
        it('creates affiliations for a contributor', function () {
            $this->rorLookup->shouldReceive('isRorUrl')->andReturn(false);

            $contributor = ResourceContributor::factory()->forPerson()->create();

            $this->service->syncForContributor($contributor, [
                'affiliations' => [
                    ['value' => 'MIT'],
                ],
            ]);

            expect($contributor->affiliations()->count())->toBe(1);
            expect($contributor->affiliations()->first()->name)->toBe('MIT');
        });

        it('does nothing when no affiliations provided', function () {
            $contributor = ResourceContributor::factory()->forPerson()->create();

            $this->service->syncForContributor($contributor, []);

            expect($contributor->affiliations()->count())->toBe(0);
        });
    });
});
