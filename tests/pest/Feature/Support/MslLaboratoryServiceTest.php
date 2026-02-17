<?php

declare(strict_types=1);

use App\Support\MslLaboratoryService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->service = new MslLaboratoryService;
    $this->service->clearCache();
});

function fakeLabResponse(array $labs = []): void
{
    Http::fake([
        'raw.githubusercontent.com/*' => Http::response($labs, 200),
    ]);
}

function defaultLab(array $overrides = []): array
{
    return array_merge([
        'identifier' => 'test123',
        'name' => 'Test Lab',
        'affiliation_name' => 'Test University',
        'affiliation_ror' => 'https://ror.org/test',
    ], $overrides);
}

describe('findByLabId', function () {
    test('returns laboratory', function () {
        fakeLabResponse([defaultLab()]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeArray()
            ->and($lab['name'])->toBe('Test Lab')
            ->and($lab['identifier'])->toBe('test123');
    });

    test('caches result', function () {
        fakeLabResponse([defaultLab()]);

        $this->service->findByLabId('test123');

        Http::assertSentCount(1);

        $lab = $this->service->findByLabId('test123');

        Http::assertSentCount(1);

        expect($lab)->toBeArray();
    });

    test('returns null for unknown id', function () {
        fakeLabResponse([defaultLab()]);

        $lab = $this->service->findByLabId('unknown456');

        expect($lab)->toBeNull();
    });
});

describe('isValidLabId', function () {
    test('returns true for existing id', function () {
        fakeLabResponse([defaultLab()]);

        expect($this->service->isValidLabId('test123'))->toBeTrue();
    });

    test('returns false for unknown id', function () {
        fakeLabResponse([defaultLab()]);

        expect($this->service->isValidLabId('unknown456'))->toBeFalse();
    });
});

describe('enrichLaboratoryData', function () {
    test('uses vocabulary data', function () {
        fakeLabResponse([defaultLab([
            'name' => 'Official Lab Name',
            'affiliation_name' => 'Official University',
            'affiliation_ror' => 'https://ror.org/official',
        ])]);

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

    test('falls back to xml data', function () {
        fakeLabResponse([defaultLab(['identifier' => 'other123', 'name' => 'Other Lab'])]);

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

    test('handles empty xml data', function () {
        fakeLabResponse([defaultLab(['identifier' => 'other123'])]);

        $enriched = $this->service->enrichLaboratoryData('unknown456');

        expect($enriched['identifier'])->toBe('unknown456')
            ->and($enriched['name'])->toBe('')
            ->and($enriched['affiliation_name'])->toBe('')
            ->and($enriched['affiliation_ror'])->toBe('');
    });
});

describe('clearCache', function () {
    test('removes cached data', function () {
        fakeLabResponse([defaultLab()]);

        $this->service->findByLabId('test123');
        Http::assertSentCount(1);

        $this->service->clearCache();

        // After clearing cache, the next call should make a new HTTP request
        // Don't re-fake to avoid resetting the request counter
        $this->service->findByLabId('test123');
        Http::assertSentCount(2);
    });
});

describe('error handling', function () {
    test('handles http error gracefully', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 500),
        ]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeNull();
    });

    test('handles invalid json gracefully', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('not valid json', 200),
        ]);

        $lab = $this->service->findByLabId('test123');

        expect($lab)->toBeNull();
    });

    test('handles malformed laboratory data', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                ['name' => 'Invalid Lab'],
                defaultLab(['identifier' => 'valid123', 'name' => 'Valid Lab']),
            ], 200),
        ]);

        expect($this->service->findByLabId('invalid'))->toBeNull();

        $validLab = $this->service->findByLabId('valid123');
        expect($validLab)->not->toBeNull()
            ->and($validLab['name'])->toBe('Valid Lab');
    });
});
