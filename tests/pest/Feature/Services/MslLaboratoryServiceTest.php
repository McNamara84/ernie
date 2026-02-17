<?php

declare(strict_types=1);

use App\Support\MslLaboratoryService;
use Illuminate\Support\Facades\Http;

covers(MslLaboratoryService::class);

beforeEach(function () {
    $this->service = new MslLaboratoryService;
    $this->service->clearCache();
});

describe('Finding labs', function () {
    it('returns a laboratory by lab ID', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeArray()
            ->and($lab['name'])->toBe('Test Lab')
            ->and($lab['identifier'])->toBe('test123');
    });

    it('returns null for an unknown lab ID', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $lab = $this->service->findByLabId('unknown456');

        expect($lab)->toBeNull();
    });

    it('validates an existing lab ID as valid', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        expect($this->service->isValidLabId('test123'))->toBeTrue();
    });

    it('validates an unknown lab ID as invalid', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        expect($this->service->isValidLabId('unknown456'))->toBeFalse();
    });
});

describe('Cache behavior', function () {
    it('caches the result after the first HTTP call', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $this->service->findByLabId('test123');
        Http::assertSentCount(1);

        $lab = $this->service->findByLabId('test123');
        Http::assertSentCount(1);

        expect($lab)->toBeArray();
    });

    it('makes a new HTTP request after clearing cache', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Test Lab',
                    'affiliation_name' => 'Test University',
                    'affiliation_ror' => 'https://ror.org/test',
                ],
            ], 200),
        ]);

        $this->service->findByLabId('test123');
        Http::assertSentCount(1);

        $this->service->clearCache();

        $this->service->findByLabId('test123');
        Http::assertSentCount(2);
    });
});

describe('Data enrichment', function () {
    it('uses vocabulary data when lab ID is found', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'test123',
                    'name' => 'Official Lab Name',
                    'affiliation_name' => 'Official University',
                    'affiliation_ror' => 'https://ror.org/official',
                ],
            ], 200),
        ]);

        $enriched = $this->service->enrichLaboratoryData(
            'test123',
            'XML Lab Name',
            'XML University',
            'https://ror.org/xml'
        );

        expect($enriched['name'])->toBe('Official Lab Name')
            ->and($enriched['affiliation_name'])->toBe('Official University')
            ->and($enriched['affiliation_ror'])->toBe('https://ror.org/official');
    });

    it('falls back to XML data when lab ID is not found', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'other123',
                    'name' => 'Other Lab',
                    'affiliation_name' => 'Other University',
                    'affiliation_ror' => 'https://ror.org/other',
                ],
            ], 200),
        ]);

        $enriched = $this->service->enrichLaboratoryData(
            'unknown456',
            'XML Lab Name',
            'XML University',
            'https://ror.org/xml'
        );

        expect($enriched['name'])->toBe('XML Lab Name')
            ->and($enriched['affiliation_name'])->toBe('XML University')
            ->and($enriched['affiliation_ror'])->toBe('https://ror.org/xml');
    });

    it('returns empty strings when lab ID is unknown and no XML data provided', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'identifier' => 'other123',
                    'name' => 'Other Lab',
                    'affiliation_name' => 'Other University',
                    'affiliation_ror' => 'https://ror.org/other',
                ],
            ], 200),
        ]);

        $enriched = $this->service->enrichLaboratoryData('unknown456');

        expect($enriched['identifier'])->toBe('unknown456')
            ->and($enriched['name'])->toBe('')
            ->and($enriched['affiliation_name'])->toBe('')
            ->and($enriched['affiliation_ror'])->toBe('');
    });
});

describe('Error handling', function () {
    it('handles HTTP errors gracefully', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 500),
        ]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeNull();
    });

    it('handles invalid JSON responses gracefully', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('not valid json', 200),
        ]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeNull();
    });

    it('handles malformed laboratory data', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    // Missing identifier field
                    'name' => 'Invalid Lab',
                ],
                [
                    'identifier' => 'valid123',
                    'name' => 'Valid Lab',
                    'affiliation_name' => 'University',
                    'affiliation_ror' => 'https://ror.org/valid',
                ],
            ], 200),
        ]);

        $invalidLab = $this->service->findByLabId('invalid');
        expect($invalidLab)->toBeNull();

        $validLab = $this->service->findByLabId('valid123');
        expect($validLab)->not->toBeNull()
            ->and($validLab['name'])->toBe('Valid Lab');
    });
});
