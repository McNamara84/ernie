<?php

declare(strict_types=1);

use App\Services\MslKeywordTransformer;

covers(MslKeywordTransformer::class);

// =========================================================================
// transform()
// =========================================================================

describe('transform', function () {
    it('transforms an EPOS WP16 Analogue keyword correctly', function () {
        $oldKeyword = (object) [
            'keyword' => 'Sand > Quartz Sand',
            'thesaurus' => 'EPOS WP16 Analogue Material',
            'uri' => 'http://epos/WP16Vocabulary/AnalogueMaterial/Sand/Quartz',
            'description' => 'Sample material description',
        ];

        $result = MslKeywordTransformer::transform($oldKeyword);

        expect($result)->not->toBeNull()
            ->and($result['text'])->toBe('Quartz Sand')
            ->and($result['path'])->toBe('Sand > Quartz Sand')
            ->and($result['language'])->toBe('en')
            ->and($result['scheme'])->toBe('EPOS MSL vocabulary')
            ->and($result['schemeURI'])->toBe('https://epos-msl.uu.nl/voc')
            ->and($result['description'])->toBe('Sample material description')
            ->and($result['id'])->toContain('https://epos-msl.uu.nl/voc/materials/1.3/');
    });

    it('transforms a Rock Physics keyword correctly', function () {
        $oldKeyword = (object) [
            'keyword' => 'Granite',
            'thesaurus' => 'EPOS WP16 Rock Physics Material',
            'uri' => 'http://epos/WP16Vocabulary/RockPhysicsMaterial/Granite',
            'description' => null,
        ];

        $result = MslKeywordTransformer::transform($oldKeyword);

        expect($result)->not->toBeNull()
            ->and($result['text'])->toBe('Granite')
            ->and($result['id'])->toContain('materials/1.3/')
            ->and($result['description'])->toBeNull();
    });

    it('returns null for non-EPOS keywords', function () {
        $oldKeyword = (object) [
            'keyword' => 'Climate',
            'thesaurus' => 'NASA/GCMD Earth Science Keywords',
            'uri' => 'https://gcmd.example.com/123',
            'description' => null,
        ];

        expect(MslKeywordTransformer::transform($oldKeyword))->toBeNull();
    });

    it('returns null for unknown thesaurus name', function () {
        $oldKeyword = (object) [
            'keyword' => 'Something',
            'thesaurus' => 'EPOS WP16 Totally Unknown Category',
            'uri' => 'http://epos/WP16Vocabulary/Unknown/Something',
            'description' => null,
        ];

        expect(MslKeywordTransformer::transform($oldKeyword))->toBeNull();
    });

    it('handles missing properties gracefully', function () {
        $oldKeyword = (object) [
            'thesaurus' => 'EPOS WP16 Analogue Apparatus',
        ];

        $result = MslKeywordTransformer::transform($oldKeyword);

        expect($result)->not->toBeNull()
            ->and($result['text'])->toBe('')
            ->and($result['path'])->toBe('');
    });

    it('constructs URI from keyword path when old URI is empty', function () {
        $oldKeyword = (object) [
            'keyword' => 'Spring > Compression Spring',
            'thesaurus' => 'EPOS WP16 Analogue Apparatus',
            'uri' => '',
            'description' => null,
        ];

        $result = MslKeywordTransformer::transform($oldKeyword);

        expect($result)->not->toBeNull()
            ->and($result['id'])->toBe('https://epos-msl.uu.nl/voc/apparatus/1.3/spring-compression_spring');
    });

    it('maps each category to correct vocabulary path', function (string $thesaurus, string $expectedPath) {
        $result = MslKeywordTransformer::transform((object) [
            'keyword' => 'Test',
            'thesaurus' => $thesaurus,
            'uri' => '',
            'description' => null,
        ]);

        expect($result['id'])->toContain("/{$expectedPath}/");
    })->with([
        ['EPOS WP16 Analogue Material', 'materials'],
        ['EPOS WP16 Analogue Apparatus', 'apparatus'],
        ['EPOS WP16 Analogue Monitoring', 'monitoring'],
        ['EPOS WP16 Analogue Software', 'software'],
        ['EPOS WP16 Analogue Measured Property', 'measured-properties'],
        ['EPOS WP16 Analogue Main Setting', 'main-settings'],
        ['EPOS WP16 Analogue Geologic Feature', 'geologic-features'],
        ['EPOS WP16 Analogue Geologic Structure', 'geologic-structures'],
        ['EPOS WP16 Analogue Process/Hazard', 'processes'],
    ]);
});

// =========================================================================
// transformMany()
// =========================================================================

describe('transformMany', function () {
    it('transforms multiple keywords filtering out non-MSL ones', function () {
        $keywords = [
            (object) ['keyword' => 'Sand', 'thesaurus' => 'EPOS WP16 Analogue Material', 'uri' => '', 'description' => null],
            (object) ['keyword' => 'Climate', 'thesaurus' => 'NASA/GCMD Science', 'uri' => '', 'description' => null],
            (object) ['keyword' => 'Sensor', 'thesaurus' => 'EPOS WP16 Rock Physics Monitoring', 'uri' => '', 'description' => null],
        ];

        $results = MslKeywordTransformer::transformMany($keywords);

        expect($results)->toHaveCount(2)
            ->and($results[0]['text'])->toBe('Sand')
            ->and($results[1]['text'])->toBe('Sensor');
    });

    it('returns empty array when no keywords match', function () {
        $keywords = [
            (object) ['keyword' => 'Climate', 'thesaurus' => 'NASA/GCMD Science', 'uri' => '', 'description' => null],
        ];

        expect(MslKeywordTransformer::transformMany($keywords))->toBeEmpty();
    });

    it('handles empty input', function () {
        expect(MslKeywordTransformer::transformMany([]))->toBeEmpty();
    });
});

// =========================================================================
// getSupportedThesauri()
// =========================================================================

describe('getSupportedThesauri', function () {
    it('returns all 18 supported thesaurus names', function () {
        $thesauri = MslKeywordTransformer::getSupportedThesauri();

        expect($thesauri)->toHaveCount(18)
            ->and($thesauri)->each->toStartWith('EPOS WP16');
    });

    it('includes both Analogue and Rock Physics variants', function () {
        $thesauri = MslKeywordTransformer::getSupportedThesauri();

        $analogue = array_filter($thesauri, fn (string $t) => str_contains($t, 'Analogue'));
        $rockPhysics = array_filter($thesauri, fn (string $t) => str_contains($t, 'Rock Physics'));

        expect($analogue)->toHaveCount(9)
            ->and($rockPhysics)->toHaveCount(9);
    });
});
