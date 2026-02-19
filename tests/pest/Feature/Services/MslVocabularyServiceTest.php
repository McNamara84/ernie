<?php

declare(strict_types=1);

use App\Services\MslVocabularyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(MslVocabularyService::class);

beforeEach(function () {
    $this->service = new MslVocabularyService;
});

// =========================================================================
// downloadAndTransformVocabulary()
// =========================================================================

describe('downloadAndTransformVocabulary', function () {
    it('downloads, transforms, and stores vocabulary', function () {
        $sourceData = [
            [
                'text' => 'Rock',
                'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/material/1.3/rock', 'description' => 'A rock'],
                'children' => [
                    [
                        'text' => 'Granite',
                        'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/material/1.3/granite', 'description' => 'Igneous rock'],
                    ],
                ],
            ],
        ];

        Http::fake([
            '*msl_vocabularies*' => Http::response($sourceData, 200),
        ]);

        Storage::fake('local');

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();
        Storage::assertExists('msl-vocabulary.json');

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);

        expect($stored)->toHaveCount(1)
            ->and($stored[0]['id'])->toBe('https://epos-msl.uu.nl/voc/material/1.3/rock')
            ->and($stored[0]['text'])->toBe('Rock')
            ->and($stored[0]['scheme'])->toBe('EPOS MSL vocabulary')
            ->and($stored[0]['schemeURI'])->toBe('https://epos-msl.uu.nl/voc')
            ->and($stored[0]['language'])->toBe('en')
            ->and($stored[0]['children'])->toHaveCount(1)
            ->and($stored[0]['children'][0]['text'])->toBe('Granite');
    });

    it('returns false on HTTP failure', function () {
        Http::fake([
            '*msl_vocabularies*' => Http::response('Not Found', 404),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
    });

    it('returns false on invalid JSON response', function () {
        Http::fake([
            '*msl_vocabularies*' => Http::response('not-json-string', 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
    });

    it('skips nodes without text', function () {
        $sourceData = [
            ['text' => 'Valid', 'extra' => ['uri' => 'https://example.com/1']],
            ['extra' => ['uri' => 'https://example.com/2']], // No text
            ['text' => null, 'extra' => ['uri' => 'https://example.com/3']], // Null text
        ];

        Http::fake([
            '*msl_vocabularies*' => Http::response($sourceData, 200),
        ]);

        Storage::fake('local');

        $this->service->downloadAndTransformVocabulary();

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);

        expect($stored)->toHaveCount(1)
            ->and($stored[0]['text'])->toBe('Valid');
    });
});

// =========================================================================
// getVocabulary()
// =========================================================================

describe('getVocabulary', function () {
    it('returns stored vocabulary data', function () {
        Storage::fake('local');

        $data = [
            [
                'id' => 'https://example.com/concept/1',
                'text' => 'Concept A',
                'language' => 'en',
                'scheme' => 'EPOS MSL vocabulary',
                'schemeURI' => 'https://epos-msl.uu.nl/voc',
                'description' => 'A concept',
            ],
        ];

        Storage::put('msl-vocabulary.json', json_encode($data));

        $result = $this->service->getVocabulary();

        expect($result)->toHaveCount(1)
            ->and($result[0]['text'])->toBe('Concept A');
    });

    it('returns empty array when file does not exist', function () {
        Storage::fake('local');

        $result = $this->service->getVocabulary();

        expect($result)->toBeEmpty();
    });

    it('returns empty array on invalid JSON', function () {
        Storage::fake('local');

        Storage::put('msl-vocabulary.json', 'invalid-json{');

        $result = $this->service->getVocabulary();

        expect($result)->toBeEmpty();
    });
});
