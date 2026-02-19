<?php

declare(strict_types=1);

use App\Services\OldDatasetKeywordTransformer;

// Note: covers() is intentionally omitted because OldDatasetKeywordTransformer
// is excluded from the <source> coverage configuration in phpunit.xml
// (metaworks legacy DB: only reachable from GFZ VPN, cannot be tested in CI)

// =========================================================================
// transform()
// =========================================================================

describe('transform', function () {
    it('transforms a science keyword correctly', function () {
        $old = (object) [
            'keyword' => 'EARTH SCIENCE > Atmosphere > Clouds',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-abcd-1234-abcd-123456789012',
            'description' => 'Cloud patterns',
        ];

        $result = OldDatasetKeywordTransformer::transform($old);

        expect($result)->not->toBeNull()
            ->and($result['scheme'])->toBe('Science Keywords')
            ->and($result['text'])->toBe('EARTH SCIENCE > Atmosphere > Clouds')
            ->and($result['uuid'])->toBe('12345678-abcd-1234-abcd-123456789012')
            ->and($result['id'])->toContain('12345678-abcd-1234-abcd-123456789012')
            ->and($result['description'])->toBe('Cloud patterns');
    });

    it('transforms platform keywords', function () {
        $old = (object) [
            'keyword' => 'In Situ Land-based Platforms',
            'thesaurus' => 'NASA/GCMD Earth Platforms Keywords',
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aaaabbbb-cccc-dddd-eeee-ffffffffffff',
            'description' => null,
        ];

        $result = OldDatasetKeywordTransformer::transform($old);

        expect($result)->not->toBeNull()
            ->and($result['scheme'])->toBe('Platforms');
    });

    it('transforms instrument keywords', function () {
        $old = (object) [
            'keyword' => 'Seismometers',
            'thesaurus' => 'NASA/GCMD Instruments',
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11112222-3333-4444-5555-666677778888',
            'description' => null,
        ];

        $result = OldDatasetKeywordTransformer::transform($old);

        expect($result)->not->toBeNull()
            ->and($result['scheme'])->toBe('Instruments');
    });

    it('returns null when URI has no valid UUID', function () {
        $old = (object) [
            'keyword' => 'Something',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'https://example.com/no-uuid-here',
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });

    it('returns null for unsupported thesaurus', function () {
        $old = (object) [
            'keyword' => 'Something',
            'thesaurus' => 'Unknown Thesaurus',
            'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-abcd-1234-abcd-123456789012',
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });

    it('returns null when URI is empty', function () {
        $old = (object) [
            'keyword' => 'Something',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => null,
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });
});

// =========================================================================
// mapScheme()
// =========================================================================

describe('mapScheme', function () {
    it('maps known thesaurus names to schemes', function (string $thesaurus, string $expected) {
        expect(OldDatasetKeywordTransformer::mapScheme($thesaurus))->toBe($expected);
    })->with([
        ['NASA/GCMD Earth Science Keywords', 'Science Keywords'],
        ['NASA/GCMD Earth Platforms Keywords', 'Platforms'],
        ['NASA/GCMD Platforms Keywords', 'Platforms'],
        ['GCMD Platforms', 'Platforms'],
        ['NASA/GCMD Instruments', 'Instruments'],
        ['GCMD Instruments', 'Instruments'],
    ]);

    it('returns null for unknown thesaurus', function () {
        expect(OldDatasetKeywordTransformer::mapScheme('Invented Thesaurus'))->toBeNull();
    });
});

// =========================================================================
// transformMany()
// =========================================================================

describe('transformMany', function () {
    it('transforms multiple keywords filtering out invalid ones', function () {
        $keywords = [
            (object) [
                'keyword' => 'Atmosphere',
                'thesaurus' => 'NASA/GCMD Earth Science Keywords',
                'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-aaaa-bbbb-cccc-dddddddddddd',
                'description' => null,
            ],
            (object) [
                'keyword' => 'Unknown',
                'thesaurus' => 'Unsupported',
                'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/aaaabbbb-cccc-dddd-eeee-ffffffffffff',
                'description' => null,
            ],
            (object) [
                'keyword' => 'Seismometer',
                'thesaurus' => 'GCMD Instruments',
                'uri' => 'https://gcmd.earthdata.nasa.gov/kms/concept/11112222-3333-4444-5555-666677778888',
                'description' => null,
            ],
        ];

        $results = OldDatasetKeywordTransformer::transformMany($keywords);

        expect($results)->toHaveCount(2)
            ->and($results[0]['scheme'])->toBe('Science Keywords')
            ->and($results[1]['scheme'])->toBe('Instruments');
    });

    it('returns empty array for empty input', function () {
        expect(OldDatasetKeywordTransformer::transformMany([]))->toBeEmpty();
    });
});

// =========================================================================
// getSupportedThesauri()
// =========================================================================

describe('getSupportedThesauri', function () {
    it('returns all 6 supported thesaurus names', function () {
        $thesauri = OldDatasetKeywordTransformer::getSupportedThesauri();

        expect($thesauri)->toHaveCount(6)
            ->and($thesauri)->toContain('NASA/GCMD Earth Science Keywords')
            ->and($thesauri)->toContain('GCMD Instruments');
    });
});

// =========================================================================
// deprecated methods (delegate to GcmdUriHelper)
// =========================================================================

describe('deprecated helpers', function () {
    it('extractUuidFromOldUri delegates to GcmdUriHelper', function () {
        $uuid = OldDatasetKeywordTransformer::extractUuidFromOldUri(
            'https://gcmd.earthdata.nasa.gov/kms/concept/12345678-abcd-1234-abcd-123456789012'
        );

        expect($uuid)->toBe('12345678-abcd-1234-abcd-123456789012');
    });

    it('constructNewUri delegates to GcmdUriHelper', function () {
        $uri = OldDatasetKeywordTransformer::constructNewUri('12345678-abcd-1234-abcd-123456789012');

        expect($uri)->toContain('12345678-abcd-1234-abcd-123456789012');
    });
});
