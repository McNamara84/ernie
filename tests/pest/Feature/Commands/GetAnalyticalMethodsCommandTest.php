<?php

declare(strict_types=1);

use App\Console\Commands\GetAnalyticalMethods;
use App\Models\ThesaurusSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

covers(GetAnalyticalMethods::class);

function fakeAnalyticalMethodsApiResponse(): void
{
    Http::fakeSequence('vocabs.ardc.edu.au/*')
        ->push([
            'result' => [
                'items' => [
                    [
                        '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/spectrometry',
                        'prefLabel' => ['_value' => 'Spectrometry', '_lang' => 'en'],
                        'broader' => [],
                        'notation' => 'SPEC',
                        'definition' => 'Root method category',
                    ],
                    [
                        '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry',
                        'prefLabel' => ['_value' => 'Mass spectrometry', '_lang' => 'en'],
                        'broader' => ['https://w3id.org/geochem/1.0/analyticalmethod/spectrometry'],
                        'notation' => 'MS',
                        'definition' => 'Study of matter through gas-phase ions.',
                    ],
                    [
                        '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/icpms',
                        'prefLabel' => ['_value' => 'ICP-MS', '_lang' => 'en'],
                        'broader' => ['https://w3id.org/geochem/1.0/analyticalmethod/massspectrometry'],
                        'notation' => 'ICP-MS',
                        'definition' => 'Inductively coupled plasma mass spectrometry.',
                    ],
                ],
                // No 'next' = single page
            ],
        ]);
}

it('successfully fetches and saves analytical methods vocabulary', function (): void {
    Storage::fake('local');
    fakeAnalyticalMethodsApiResponse();

    Artisan::call('get-analytical-methods');
    $output = Artisan::output();

    expect($output)->toContain('Fetching Analytical Methods vocabulary')
        ->and($output)->toContain('Fetched 3 items')
        ->and($output)->toContain('Extracted 3 concepts')
        ->and($output)->toContain('Successfully saved Analytical Methods vocabulary');

    Storage::assertExists('analytical-methods.json');

    $content = json_decode(Storage::get('analytical-methods.json'), true);
    expect($content)->toHaveKeys(['lastUpdated', 'data'])
        ->and($content['data'])->toHaveCount(1) // 1 root concept
        ->and($content['data'][0]['text'])->toBe('Spectrometry')
        ->and($content['data'][0]['notation'])->toBe('SPEC')
        ->and($content['data'][0]['children'])->toHaveCount(1)
        ->and($content['data'][0]['children'][0]['text'])->toBe('Mass spectrometry')
        ->and($content['data'][0]['children'][0]['children'])->toHaveCount(1)
        ->and($content['data'][0]['children'][0]['children'][0]['text'])->toBe('ICP-MS');
});

it('uses version from database when available', function (): void {
    Storage::fake('local');

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
        [
            'display_name' => 'Analytical Methods for Geochemistry',
            'is_active' => true,
            'is_elmo_active' => true,
            'version' => '2-0',
        ],
    );

    Http::fake([
        'vocabs.ardc.edu.au/repository/api/lda/earthchem-georoc/analytical-methods-for-geochemistry-and-cosmochemi/2-0/*' => Http::response([
            'result' => [
                'items' => [
                    [
                        '_about' => 'https://w3id.org/geochem/1.0/analyticalmethod/test',
                        'prefLabel' => ['_value' => 'Test', '_lang' => 'en'],
                        'broader' => [],
                        'notation' => 'T',
                    ],
                ],
            ],
        ]),
    ]);

    Artisan::call('get-analytical-methods');
    $output = Artisan::output();

    expect($output)->toContain('version 2-0');
    Storage::assertExists('analytical-methods.json');

    Http::assertSentCount(1);
});

it('falls back to default version when database has no version', function (): void {
    Storage::fake('local');

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_ANALYTICAL_METHODS],
        [
            'display_name' => 'Analytical Methods for Geochemistry',
            'is_active' => true,
            'is_elmo_active' => true,
            'version' => null,
        ],
    );

    fakeAnalyticalMethodsApiResponse();

    Artisan::call('get-analytical-methods');
    $output = Artisan::output();

    expect($output)->toContain('version 1-4');
    Storage::assertExists('analytical-methods.json');
});

it('returns failure on API error', function (): void {
    Storage::fake('local');

    Http::fake([
        'vocabs.ardc.edu.au/*' => Http::response(null, 500),
    ]);

    $exitCode = Artisan::call('get-analytical-methods');

    expect($exitCode)->toBe(1);
    Storage::assertMissing('analytical-methods.json');
});
