<?php

use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    Storage::fake();
});

function createTestInstrumentsVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2025-10-08 12:22:38',
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-instrument-id',
                'text' => 'Test Instrument',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
                'description' => 'Test instrument description',
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-instrument-child-id',
                        'text' => 'Child Instrument',
                        'language' => 'en',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/instruments',
                        'description' => 'Child instrument description',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('gcmd-instruments.json', json_encode($testData));
}

it('returns GCMD Instruments vocabulary', function () {
    createTestInstrumentsVocabularyFile();

    $response = getJson('/api/v1/vocabularies/gcmd-instruments')
        ->assertOk()
        ->assertJsonStructure([
            'lastUpdated',
            'data' => [
                '*' => [
                    'id',
                    'text',
                    'language',
                    'scheme',
                    'schemeURI',
                    'description',
                    'children',
                ],
            ],
        ]);

    expect($response->json('lastUpdated'))->toBe('2025-10-08 12:22:38');
    expect($response->json('data.0.text'))->toBe('Test Instrument');
    expect($response->json('data.0.children.0.text'))->toBe('Child Instrument');
});

it('returns 404 when instruments file does not exist', function () {
    getJson('/api/v1/vocabularies/gcmd-instruments')
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-gcmd-instruments',
        ]);
});

it('rejects instruments requests without an API key when one is configured', function () {
    createTestInstrumentsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-instruments')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects instruments requests with an invalid API key', function () {
    createTestInstrumentsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows instruments requests with a valid API key header', function () {
    createTestInstrumentsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'secret-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Instrument');
});

it('allows instruments requests with a valid API key query parameter', function () {
    createTestInstrumentsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/gcmd-instruments?api_key=secret-key')
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Instrument');
});
