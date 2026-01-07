<?php

declare(strict_types=1);

use App\Models\Person;
use App\Models\Resource;
use App\Models\ResourceContributor;
use App\Models\ResourceCreator;
use App\Services\Entities\AffiliationService;

describe('AffiliationService', function () {
    beforeEach(function () {
        $this->service = new AffiliationService;
    });

    describe('parseAffiliationsFromData', function () {
        it('parses valid affiliations', function () {
            $data = [
                'affiliations' => [
                    ['value' => 'University of Example', 'rorId' => 'https://ror.org/12345'],
                    ['value' => 'Another Institution', 'rorId' => null],
                ],
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result)->toHaveCount(2);
            expect($result[0])->toBe([
                'name' => 'University of Example',
                'identifier' => 'https://ror.org/12345',
                'identifier_scheme' => 'ROR',
            ]);
            expect($result[1])->toBe([
                'name' => 'Another Institution',
                'identifier' => null,
                'identifier_scheme' => null,
            ]);
        });

        it('filters out empty affiliations', function () {
            $data = [
                'affiliations' => [
                    ['value' => '', 'rorId' => null],
                    ['value' => '  ', 'rorId' => 'https://ror.org/12345'],
                    ['value' => 'Valid Institution'],
                ],
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('Valid Institution');
        });

        it('handles missing affiliations key', function () {
            $data = [];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result)->toBe([]);
        });

        it('handles non-array affiliations', function () {
            $data = [
                'affiliations' => 'not an array',
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result)->toBe([]);
        });

        it('filters out non-array affiliation entries', function () {
            $data = [
                'affiliations' => [
                    'string value',
                    123,
                    null,
                    ['value' => 'Valid'],
                ],
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result)->toHaveCount(1);
            expect($result[0]['name'])->toBe('Valid');
        });

        it('trims whitespace from values and ROR IDs', function () {
            $data = [
                'affiliations' => [
                    ['value' => '  Trimmed Name  ', 'rorId' => '  https://ror.org/trimmed  '],
                ],
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result[0]['name'])->toBe('Trimmed Name');
            expect($result[0]['identifier'])->toBe('https://ror.org/trimmed');
        });

        it('handles empty string ROR IDs', function () {
            $data = [
                'affiliations' => [
                    ['value' => 'Institution', 'rorId' => ''],
                    ['value' => 'Institution 2', 'rorId' => '   '],
                ],
            ];

            $result = $this->service->parseAffiliationsFromData($data);

            expect($result[0]['identifier'])->toBeNull();
            expect($result[0]['identifier_scheme'])->toBeNull();
            expect($result[1]['identifier'])->toBeNull();
        });
    });

    describe('syncForCreator', function () {
        it('creates affiliations for a resource creator', function () {
            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            $creator = ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_id' => $person->id,
                'creatorable_type' => Person::class,
            ]);

            $data = [
                'affiliations' => [
                    ['value' => 'Test University', 'rorId' => 'https://ror.org/test'],
                ],
            ];

            $this->service->syncForCreator($creator, $data);

            expect($creator->affiliations)->toHaveCount(1);
            expect($creator->affiliations->first()->name)->toBe('Test University');
            expect($creator->affiliations->first()->identifier)->toBe('https://ror.org/test');
        });

        it('does nothing when affiliations are empty', function () {
            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            $creator = ResourceCreator::factory()->create([
                'resource_id' => $resource->id,
                'creatorable_id' => $person->id,
                'creatorable_type' => Person::class,
            ]);

            $this->service->syncForCreator($creator, ['affiliations' => []]);

            expect($creator->affiliations)->toHaveCount(0);
        });
    });

    describe('syncForContributor', function () {
        it('creates affiliations for a resource contributor', function () {
            $resource = Resource::factory()->create();
            $person = Person::factory()->create();
            $contributor = ResourceContributor::factory()->create([
                'resource_id' => $resource->id,
                'contributorable_id' => $person->id,
                'contributorable_type' => Person::class,
            ]);

            $data = [
                'affiliations' => [
                    ['value' => 'Research Institute', 'rorId' => null],
                ],
            ];

            $this->service->syncForContributor($contributor, $data);

            expect($contributor->affiliations)->toHaveCount(1);
            expect($contributor->affiliations->first()->name)->toBe('Research Institute');
            expect($contributor->affiliations->first()->identifier)->toBeNull();
        });
    });
});
