<?php

declare(strict_types=1);

use App\Support\MslLaboratoryService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

covers(MslLaboratoryService::class);

beforeEach(function (): void {
    Cache::forget('msl_laboratories');
    config(['msl.vocabulary_url' => 'https://example.com/msl-labs.json']);
});

describe('findByLabId', function (): void {
    test('returns laboratory data for known ID', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([
                [
                    'identifier' => 'lab-001',
                    'name' => 'Rock Physics Lab',
                    'affiliation_name' => 'GFZ Helmholtz Centre',
                    'affiliation_ror' => 'https://ror.org/04z8jg394',
                ],
            ]),
        ]);

        $service = new MslLaboratoryService;
        $result = $service->findByLabId('lab-001');

        expect($result)->not->toBeNull();
        expect($result['name'])->toBe('Rock Physics Lab');
        expect($result['affiliation_ror'])->toBe('https://ror.org/04z8jg394');
    });

    test('returns null for unknown lab ID', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([
                ['identifier' => 'lab-001', 'name' => 'Lab One', 'affiliation_name' => '', 'affiliation_ror' => ''],
            ]),
        ]);

        $service = new MslLaboratoryService;

        expect($service->findByLabId('unknown'))->toBeNull();
    });

    test('returns empty when HTTP fails', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response(null, 500),
        ]);

        $service = new MslLaboratoryService;

        expect($service->findByLabId('lab-001'))->toBeNull();
    });
});

describe('isValidLabId', function (): void {
    test('returns true for known lab ID', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([
                ['identifier' => 'lab-001', 'name' => 'Lab', 'affiliation_name' => '', 'affiliation_ror' => ''],
            ]),
        ]);

        $service = new MslLaboratoryService;

        expect($service->isValidLabId('lab-001'))->toBeTrue();
    });

    test('returns false for unknown lab ID', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([]),
        ]);

        $service = new MslLaboratoryService;

        expect($service->isValidLabId('unknown'))->toBeFalse();
    });
});

describe('enrichLaboratoryData', function (): void {
    test('enriches from vocabulary when found', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([
                [
                    'identifier' => 'lab-001',
                    'name' => 'Vocabulary Name',
                    'affiliation_name' => 'Vocabulary Affiliation',
                    'affiliation_ror' => 'https://ror.org/04z8jg394',
                ],
            ]),
        ]);

        $service = new MslLaboratoryService;
        $result = $service->enrichLaboratoryData('lab-001', 'Fallback Name', 'Fallback Aff');

        expect($result['name'])->toBe('Vocabulary Name');
        expect($result['affiliation_name'])->toBe('Vocabulary Affiliation');
    });

    test('uses fallback data when lab not in vocabulary', function (): void {
        Http::fake([
            'example.com/msl-labs.json' => Http::response([]),
        ]);

        $service = new MslLaboratoryService;
        $result = $service->enrichLaboratoryData('unknown', 'Fallback Name', 'Fallback Aff', 'https://ror.org/123');

        expect($result['name'])->toBe('Fallback Name');
        expect($result['affiliation_name'])->toBe('Fallback Aff');
        expect($result['affiliation_ror'])->toBe('https://ror.org/123');
    });
});
