<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
    Storage::fake();
    // Clear cache to ensure each test starts fresh
    Cache::flush();
});

function createTestMslVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2025-11-07 10:30:00',
        'data' => [
            [
                'id' => 'http://w3id.org/msl/voc/materials/material',
                'text' => 'Material',
                'language' => 'en',
                'scheme' => 'EPOS Multi-Scale Laboratories Vocabulary',
                'schemeURI' => 'http://w3id.org/msl/voc/materials',
                'description' => 'Sample material type',
                'children' => [
                    [
                        'id' => 'http://w3id.org/msl/voc/materials/rock',
                        'text' => 'Rock',
                        'language' => 'en',
                        'scheme' => 'EPOS Multi-Scale Laboratories Vocabulary',
                        'schemeURI' => 'http://w3id.org/msl/voc/materials',
                        'description' => 'Rock sample',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('msl-vocabulary.json', json_encode($testData));
}

it('returns MSL vocabulary', function () {
    createTestMslVocabularyFile();

    $response = getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'test-api-key'])
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

    expect($response->json('lastUpdated'))->toBe('2025-11-07 10:30:00');
    expect($response->json('data.0.text'))->toBe('Material');
    expect($response->json('data.0.children.0.text'))->toBe('Rock');
});

it('returns 404 when MSL vocabulary file does not exist', function () {
    // Error handling occurs in the cache callback, which throws VocabularyNotFoundException
    getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-msl-keywords',
        ]);
});

it('rejects MSL vocabulary requests without an API key when one is configured', function () {
    createTestMslVocabularyFile();

    getJson('/api/v1/vocabularies/msl')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects MSL vocabulary requests with an invalid API key', function () {
    createTestMslVocabularyFile();

    getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows MSL vocabulary requests with a valid API key header', function () {
    createTestMslVocabularyFile();

    $response = getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'test-api-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Material');
});

it('rejects API keys in query parameters for security', function () {
    createTestMslVocabularyFile();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/vocabularies/msl?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects MSL vocabulary requests when no API key is configured on server', function () {
    createTestMslVocabularyFile();

    config(['services.ernie.api_key' => null]);

    getJson('/api/v1/vocabularies/msl')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
