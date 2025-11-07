<?php

use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    Storage::fake();
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

    $response = getJson('/api/v1/vocabularies/msl')
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
    getJson('/api/v1/vocabularies/msl')
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-msl-keywords',
        ]);
});

it('rejects MSL vocabulary requests without an API key when one is configured', function () {
    createTestMslVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/msl')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects MSL vocabulary requests with an invalid API key', function () {
    createTestMslVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows MSL vocabulary requests with a valid API key header', function () {
    createTestMslVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/msl', ['X-API-Key' => 'secret-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Material');
});

it('allows MSL vocabulary requests with a valid API key query parameter', function () {
    createTestMslVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/msl?api_key=secret-key')
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Material');
    expect($response->json('data.0.children.0.text'))->toBe('Rock');
});
