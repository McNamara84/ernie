<?php

declare(strict_types=1);

use App\Services\MslVocabularyService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

covers(MslVocabularyService::class);

describe('downloadAndTransformVocabulary', function (): void {
    test('downloads and transforms vocabulary', function (): void {
        Storage::fake();

        Http::fake([
            'raw.githubusercontent.com/*' => Http::response([
                [
                    'text' => 'Rock Physics',
                    'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/rock', 'description' => 'Rock physics concepts'],
                    'children' => [
                        [
                            'text' => 'Density',
                            'extra' => ['uri' => 'https://epos-msl.uu.nl/voc/density', 'description' => 'Density measurement'],
                        ],
                    ],
                ],
            ]),
        ]);

        $service = new MslVocabularyService;
        $result = $service->downloadAndTransformVocabulary();

        expect($result)->toBeTrue();
        expect(Storage::exists('msl-vocabulary.json'))->toBeTrue();
    });

    test('returns false on HTTP failure', function (): void {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response(null, 500),
        ]);

        $service = new MslVocabularyService;
        $result = $service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
    });

    test('returns false on non-array response', function (): void {
        Http::fake([
            'raw.githubusercontent.com/*' => Http::response('not json'),
        ]);

        $service = new MslVocabularyService;
        $result = $service->downloadAndTransformVocabulary();

        expect($result)->toBeFalse();
    });
});

describe('getVocabulary', function (): void {
    test('returns vocabulary from storage', function (): void {
        Storage::fake();
        Storage::put('msl-vocabulary.json', json_encode([
            ['id' => 'https://epos-msl.uu.nl/voc/rock', 'text' => 'Rock Physics'],
        ]));

        $service = new MslVocabularyService;
        $result = $service->getVocabulary();

        expect($result)->toHaveCount(1);
        expect($result[0]['text'])->toBe('Rock Physics');
    });

    test('returns empty array when file does not exist', function (): void {
        Storage::fake();

        $service = new MslVocabularyService;
        $result = $service->getVocabulary();

        expect($result)->toBeEmpty();
    });

    test('returns empty array for invalid JSON', function (): void {
        Storage::fake();
        Storage::put('msl-vocabulary.json', 'not json');

        $service = new MslVocabularyService;
        $result = $service->getVocabulary();

        expect($result)->toBeEmpty();
    });
});
