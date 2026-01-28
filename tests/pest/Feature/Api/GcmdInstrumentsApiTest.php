<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.elmo.api_key' => 'test-api-key']);
    Storage::fake();
    // Clear cache to ensure each test starts fresh
    Cache::flush();
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

    $response = getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'test-api-key'])
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
    getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-gcmd-instruments',
        ]);
});

it('rejects instruments requests without an API key when one is configured', function () {
    createTestInstrumentsVocabularyFile();

    getJson('/api/v1/vocabularies/gcmd-instruments')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects instruments requests with an invalid API key', function () {
    createTestInstrumentsVocabularyFile();

    getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows instruments requests with a valid API key header', function () {
    createTestInstrumentsVocabularyFile();

    $response = getJson('/api/v1/vocabularies/gcmd-instruments', ['X-API-Key' => 'test-api-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Instrument');
});

it('rejects API keys in query parameters for security', function () {
    createTestInstrumentsVocabularyFile();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/vocabularies/gcmd-instruments?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects instruments requests when no API key is configured on server', function () {
    createTestInstrumentsVocabularyFile();

    config(['services.elmo.api_key' => null]);

    getJson('/api/v1/vocabularies/gcmd-instruments')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
