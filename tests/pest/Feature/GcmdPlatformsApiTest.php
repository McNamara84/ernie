<?php

use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

beforeEach(function () {
    config(['services.elmo.api_key' => null]);
    Storage::fake();
});

function createTestPlatformsFile(): void
{
    $testData = [
        'lastUpdated' => '2025-10-08 12:13:45',
        'data' => [
            [
                'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-platform-id',
                'text' => 'Test Platform',
                'language' => 'en',
                'scheme' => 'NASA/GCMD Earth Science Keywords',
                'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
                'description' => 'Test platform description',
                'children' => [
                    [
                        'id' => 'https://gcmd.earthdata.nasa.gov/kms/concept/test-platform-child-id',
                        'text' => 'Child Platform',
                        'language' => 'en',
                        'scheme' => 'NASA/GCMD Earth Science Keywords',
                        'schemeURI' => 'https://gcmd.earthdata.nasa.gov/kms/concepts/concept_scheme/platforms',
                        'description' => 'Child platform description',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('gcmd-platforms.json', json_encode($testData));
}

it('returns GCMD Platforms vocabulary', function () {
    createTestPlatformsFile();

    $response = getJson('/api/v1/vocabularies/gcmd-platforms')
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

    expect($response->json('lastUpdated'))->toBe('2025-10-08 12:13:45');
    expect($response->json('data.0.text'))->toBe('Test Platform');
    expect($response->json('data.0.children.0.text'))->toBe('Child Platform');
});

it('returns 404 when platforms file does not exist', function () {
    getJson('/api/v1/vocabularies/gcmd-platforms')
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-gcmd-platforms',
        ]);
});

it('rejects platforms requests without an API key when one is configured', function () {
    createTestPlatformsFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-platforms')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects platforms requests with an invalid API key', function () {
    createTestPlatformsFile();

    config(['services.elmo.api_key' => 'secret-key']);

    getJson('/api/v1/vocabularies/gcmd-platforms', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows platforms requests with a valid API key header', function () {
    createTestPlatformsFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/gcmd-platforms', ['X-API-Key' => 'secret-key'])
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Platform');
});

it('allows platforms requests with a valid API key query parameter', function () {
    createTestPlatformsFile();

    config(['services.elmo.api_key' => 'secret-key']);

    $response = getJson('/api/v1/vocabularies/gcmd-platforms?api_key=secret-key')
        ->assertOk();

    expect($response->json('data.0.text'))->toBe('Test Platform');
});
