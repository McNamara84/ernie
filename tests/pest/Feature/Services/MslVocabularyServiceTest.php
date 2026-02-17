<?php

declare(strict_types=1);

use App\Services\MslVocabularyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->service = new MslVocabularyService;
    Storage::fake();
});

describe('downloadAndTransformVocabulary', function () {
    test('downloads and stores vocabulary successfully', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'text' => 'Materials',
                    'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/materials', 'description' => 'Material types'],
                    'children' => [
                        [
                            'text' => 'Rock',
                            'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/materials/rock', 'description' => 'Rock material'],
                            'children' => [],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();
        Storage::assertExists('msl-vocabulary.json');

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);
        expect($stored)->toBeArray()
            ->and($stored[0]['text'])->toBe('Materials')
            ->and($stored[0]['scheme'])->toBe('EPOS MSL vocabulary')
            ->and($stored[0]['schemeURI'])->toBe('https://epos-msl.uu.nl/voc')
            ->and($stored[0]['language'])->toBe('en')
            ->and($stored[0]['children'])->toHaveCount(1)
            ->and($stored[0]['children'][0]['text'])->toBe('Rock');
    });

    test('returns false on http error', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('', 500),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
        Storage::assertMissing('msl-vocabulary.json');
    });

    test('returns false on invalid json response', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('not-an-array', 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
    });

    test('skips nodes without text field', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                ['extra' => ['uri' => 'no-text']],
                ['text' => 'Valid Node', 'extra' => ['uri' => 'valid', 'description' => 'ok']],
            ], 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);
        expect($stored)->toHaveCount(1)
            ->and($stored[0]['text'])->toBe('Valid Node');
    });

    test('handles empty vocabulary tree', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([], 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);
        expect($stored)->toBe([]);
    });

    test('handles deeply nested children', function () {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'text' => 'L1',
                    'extra' => ['uri' => 'l1'],
                    'children' => [
                        [
                            'text' => 'L2',
                            'extra' => ['uri' => 'l2'],
                            'children' => [
                                [
                                    'text' => 'L3',
                                    'extra' => ['uri' => 'l3'],
                                    'children' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();

        $stored = json_decode(Storage::get('msl-vocabulary.json'), true);
        expect($stored[0]['children'][0]['children'][0]['text'])->toBe('L3');
    });
});

describe('getVocabulary', function () {
    test('returns vocabulary from storage', function () {
        $vocabData = [
            ['id' => 'test', 'text' => 'Test', 'language' => 'en', 'scheme' => 'EPOS MSL vocabulary', 'schemeURI' => 'https://epos-msl.uu.nl/voc', 'description' => ''],
        ];
        Storage::put('msl-vocabulary.json', json_encode($vocabData));

        $result = $this->service->getVocabulary();

        expect($result)->toBe($vocabData);
    });

    test('returns empty array if file does not exist', function () {
        $result = $this->service->getVocabulary();

        expect($result)->toBe([]);
    });

    test('returns empty array for invalid json', function () {
        Storage::put('msl-vocabulary.json', 'invalid json{{{');

        $result = $this->service->getVocabulary();

        expect($result)->toBe([]);
    });
});
