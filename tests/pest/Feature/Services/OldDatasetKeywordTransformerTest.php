<?php

declare(strict_types=1);

use App\Services\OldDatasetKeywordTransformer;

describe('transform', function () {
    test('transforms NASA/GCMD keyword with valid UUID URI', function () {
        $uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $old = (object) [
            'keyword' => 'Earth Science > Atmosphere > Precipitation',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => "http://gcmdservices.gsfc.nasa.gov/kms/concepts/concept_scheme/sciencekeywords/{$uuid}",
            'description' => null,
        ];

        $result = OldDatasetKeywordTransformer::transform($old);

        expect($result)->not->toBeNull()
            ->and($result['scheme'])->toBe('Science Keywords')
            ->and($result['uuid'])->toBe($uuid)
            ->and($result['id'])->toBe("https://gcmd.earthdata.nasa.gov/kms/concept/{$uuid}")
            ->and($result['text'])->toBe('Earth Science > Atmosphere > Precipitation');
    });

    test('returns null for keyword without valid UUID in URI', function () {
        $old = (object) [
            'keyword' => 'Test',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'http://example.com/no-uuid',
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });

    test('returns null for unsupported thesaurus', function () {
        $old = (object) [
            'keyword' => 'Test',
            'thesaurus' => 'Unknown Thesaurus',
            'uri' => 'http://example.com/a1b2c3d4-e5f6-7890-abcd-ef1234567890',
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });

    test('returns null when URI is null', function () {
        $old = (object) [
            'keyword' => 'Test',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => null,
            'description' => null,
        ];

        expect(OldDatasetKeywordTransformer::transform($old))->toBeNull();
    });
});

describe('mapScheme', function () {
    test('maps all supported schemes correctly', function () {
        expect(OldDatasetKeywordTransformer::mapScheme('NASA/GCMD Earth Science Keywords'))->toBe('Science Keywords')
            ->and(OldDatasetKeywordTransformer::mapScheme('NASA/GCMD Earth Platforms Keywords'))->toBe('Platforms')
            ->and(OldDatasetKeywordTransformer::mapScheme('NASA/GCMD Platforms Keywords'))->toBe('Platforms')
            ->and(OldDatasetKeywordTransformer::mapScheme('GCMD Platforms'))->toBe('Platforms')
            ->and(OldDatasetKeywordTransformer::mapScheme('NASA/GCMD Instruments'))->toBe('Instruments')
            ->and(OldDatasetKeywordTransformer::mapScheme('GCMD Instruments'))->toBe('Instruments');
    });

    test('returns null for unknown scheme', function () {
        expect(OldDatasetKeywordTransformer::mapScheme('Unknown'))->toBeNull();
    });
});

describe('transformMany', function () {
    test('transforms multiple keywords and filters nulls', function () {
        $uuid1 = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
        $uuid2 = 'b2c3d4e5-f6a7-8901-bcde-f12345678901';

        $keywords = [
            (object) ['keyword' => 'K1', 'thesaurus' => 'NASA/GCMD Earth Science Keywords', 'uri' => "https://gcmd.earthdata.nasa.gov/kms/concept/{$uuid1}", 'description' => null],
            (object) ['keyword' => 'K2', 'thesaurus' => 'Unknown', 'uri' => 'http://example.com', 'description' => null],
            (object) ['keyword' => 'K3', 'thesaurus' => 'NASA/GCMD Instruments', 'uri' => "https://gcmd.earthdata.nasa.gov/kms/concept/{$uuid2}", 'description' => null],
        ];

        $results = OldDatasetKeywordTransformer::transformMany($keywords);

        expect($results)->toHaveCount(2)
            ->and($results[0]['scheme'])->toBe('Science Keywords')
            ->and($results[1]['scheme'])->toBe('Instruments');
    });
});

describe('getSupportedThesauri', function () {
    test('returns all 6 supported thesaurus names', function () {
        $thesauri = OldDatasetKeywordTransformer::getSupportedThesauri();

        expect($thesauri)->toHaveCount(6)
            ->and($thesauri)->toContain('NASA/GCMD Earth Science Keywords')
            ->and($thesauri)->toContain('NASA/GCMD Instruments')
            ->and($thesauri)->toContain('GCMD Platforms');
    });
});
