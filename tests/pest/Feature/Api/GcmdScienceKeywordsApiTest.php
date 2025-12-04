<?php

use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    Storage::fake();
});

function createTestScienceKeywordsVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2025-10-08 12:00:00',
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-id',
                'text' => 'Test Keyword',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                'description' => 'Test description',
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-child-id',
                        'text' => 'Child Keyword',
                        'language' => 'en',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/sciencekeywords',
                        'description' => 'Child description',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('gcmd-science-keywords.json', json_encode($testData));
}

it('returns GCMD Science Keywords vocabulary', function () {
    createTestScienceKeywordsVocabularyFile();

    $response = getJson('/api/v1/vocabularies/gcmd-science-keywords')
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

    expect($response->json('lastUpdated'))->toBe('2025-10-08 12:00:00');
    expect($response->json('data.0.text'))->toBe('Test Keyword');
    expect($response->json('data.0.children.0.text'))->toBe('Child Keyword');
});

it('returns 404 when vocabulary file does not exist', function () {
    getJson('/api/v1/vocabularies/gcmd-science-keywords')
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-gcmd-science-keywords',
        ]);
});

it('rejects requests without an API key when one is configured', function () {
    createTestScienceKeywordsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-science-keywords')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createTestScienceKeywordsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-science-keywords', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows requests with a valid API key header', function () {
    createTestScienceKeywordsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/gcmd-science-keywords', ['X-API-Key' => 'secret-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Keyword');
});

it('rejects API keys in query parameters for security', function () {
    createTestScienceKeywordsVocabularyFile();

    config(['services.elmo.api_key' => 'secret-key']);

    // API keys in query params are rejected as they can leak via logs and Referer headers
    getJson('/api/v1/vocabularies/gcmd-science-keywords?api_key=secret-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});
