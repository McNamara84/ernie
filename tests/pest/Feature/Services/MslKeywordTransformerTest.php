<?php

declare(strict_types=1);

use App\Services\MslKeywordTransformer;

describe('transform', function () {
    test('transforms EPOS WP16 keyword to new format', function () {
        $old = (object) [
            'keyword' => 'Sand > Quartz Sand',
            'thesaurus' => 'EPOS WP16 Analogue Material',
            'uri' => 'http://epos/WP16Vocabulary/AnalogueMaterial/Sand/Quartz',
            'description' => null,
        ];

        $result = MslKeywordTransformer::transform($old);

        expect($result)->not->toBeNull()
            ->and($result['text'])->toBe('Quartz Sand')
            ->and($result['path'])->toBe('Sand > Quartz Sand')
            ->and($result['scheme'])->toBe('EPOS MSL vocabulary')
            ->and($result['schemeURI'])->toBe('https://epos-msl.uu.nl/voc')
            ->and($result['language'])->toBe('en')
            ->and($result['id'])->toContain('https://epos-msl.uu.nl/voc/materials/');
    });

    test('returns null for non-EPOS thesaurus', function () {
        $old = (object) [
            'keyword' => 'keyword',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'http://example.com',
            'description' => null,
        ];

        expect(MslKeywordTransformer::transform($old))->toBeNull();
    });

    test('returns null for unknown EPOS WP16 thesaurus', function () {
        $old = (object) [
            'keyword' => 'keyword',
            'thesaurus' => 'EPOS WP16 Unknown Category',
            'uri' => '',
            'description' => null,
        ];

        expect(MslKeywordTransformer::transform($old))->toBeNull();
    });

    test('maps all known thesauri to correct categories', function () {
        $thesauri = [
            'EPOS WP16 Analogue Material' => 'materials',
            'EPOS WP16 Analogue Apparatus' => 'apparatus',
            'EPOS WP16 Rock Physics Monitoring' => 'monitoring',
            'EPOS WP16 Analogue Software' => 'software',
            'EPOS WP16 Rock Physics Measured Property' => 'measured-properties',
        ];

        foreach ($thesauri as $thesaurus => $expectedPath) {
            $old = (object) [
                'keyword' => 'Test',
                'thesaurus' => $thesaurus,
                'uri' => '',
                'description' => null,
            ];

            $result = MslKeywordTransformer::transform($old);

            expect($result)->not->toBeNull()
                ->and($result['id'])->toContain($expectedPath);
        }
    });

    test('constructs fallback URI when old URI is empty', function () {
        $old = (object) [
            'keyword' => 'Sand > Quartz Sand',
            'thesaurus' => 'EPOS WP16 Analogue Material',
            'uri' => '',
            'description' => null,
        ];

        $result = MslKeywordTransformer::transform($old);

        expect($result['id'])->toBe('https://epos-msl.uu.nl/voc/materials/1.3/sand-quartz_sand');
    });

    test('extracts last path segment as text', function () {
        $old = (object) [
            'keyword' => 'Level 1 > Level 2 > Deep Value',
            'thesaurus' => 'EPOS WP16 Analogue Material',
            'uri' => '',
            'description' => null,
        ];

        $result = MslKeywordTransformer::transform($old);

        expect($result['text'])->toBe('Deep Value');
    });

    test('includes description when provided', function () {
        $old = (object) [
            'keyword' => 'Sand',
            'thesaurus' => 'EPOS WP16 Analogue Material',
            'uri' => '',
            'description' => 'A granular material.',
        ];

        $result = MslKeywordTransformer::transform($old);

        expect($result['description'])->toBe('A granular material.');
    });
});

describe('transformMany', function () {
    test('transforms multiple keywords and filters nulls', function () {
        $keywords = [
            (object) ['keyword' => 'Sand', 'thesaurus' => 'EPOS WP16 Analogue Material', 'uri' => '', 'description' => null],
            (object) ['keyword' => 'Other', 'thesaurus' => 'NASA/GCMD', 'uri' => '', 'description' => null],
            (object) ['keyword' => 'App', 'thesaurus' => 'EPOS WP16 Analogue Apparatus', 'uri' => '', 'description' => null],
        ];

        $results = MslKeywordTransformer::transformMany($keywords);

        expect($results)->toHaveCount(2);
    });

    test('returns empty array when no keywords match', function () {
        $keywords = [
            (object) ['keyword' => 'Other', 'thesaurus' => 'Some other scheme', 'uri' => '', 'description' => null],
        ];

        expect(MslKeywordTransformer::transformMany($keywords))->toBeEmpty();
    });
});

describe('getSupportedThesauri', function () {
    test('returns all 18 supported thesaurus names', function () {
        $thesauri = MslKeywordTransformer::getSupportedThesauri();

        expect($thesauri)->toHaveCount(18)
            ->and($thesauri)->each->toStartWith('EPOS WP16');
    });
});
