<?php

declare(strict_types=1);

use App\Http\Controllers\VocabularyController;
use App\Models\ThesaurusSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

covers(VocabularyController::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);
    Storage::fake();
    Cache::flush();
});

function createTestGemetVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2026-03-12 10:00:00',
        'data' => [
            [
                'id' => 'http://www.eionet.europa.eu/gemet/supergroup/1234',
                'text' => 'THE ENVIRONMENT, MAN AND NATURE',
                'language' => 'en',
                'scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                'schemeURI' => 'http://www.eionet.europa.eu/gemet/concept/',
                'description' => 'Super group definition',
                'children' => [
                    [
                        'id' => 'http://www.eionet.europa.eu/gemet/group/5678',
                        'text' => 'ATMOSPHERE',
                        'language' => 'en',
                        'scheme' => 'GEMET - GEneral Multilingual Environmental Thesaurus',
                        'schemeURI' => 'http://www.eionet.europa.eu/gemet/concept/',
                        'description' => 'Group definition',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('gemet-thesaurus.json', json_encode($testData));
}

it('returns GEMET vocabulary via API with valid key', function (): void {
    createTestGemetVocabularyFile();

    $response = getJson('/api/v1/vocabularies/gemet', ['X-API-Key' => 'test-api-key'])
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

    expect($response->json('lastUpdated'))->toBe('2026-03-12 10:00:00');
    expect($response->json('data.0.text'))->toBe('THE ENVIRONMENT, MAN AND NATURE');
    expect($response->json('data.0.children.0.text'))->toBe('ATMOSPHERE');
});

it('returns 404 when vocabulary file does not exist', function (): void {
    getJson('/api/v1/vocabularies/gemet', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-gemet-thesaurus',
        ]);
});

it('rejects requests without an API key', function (): void {
    createTestGemetVocabularyFile();

    getJson('/api/v1/vocabularies/gemet')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function (): void {
    createTestGemetVocabularyFile();

    getJson('/api/v1/vocabularies/gemet', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 404 when thesaurus is disabled for ELMO', function (): void {
    createTestGemetVocabularyFile();

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_GEMET],
        [
            'display_name' => 'GEMET Thesaurus',
            'is_active' => true,
            'is_elmo_active' => false,
        ]
    );

    getJson('/api/v1/vocabularies/gemet', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson(['error' => 'Thesaurus is disabled']);
});

it('returns data when thesaurus is active for ELMO', function (): void {
    createTestGemetVocabularyFile();

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_GEMET],
        [
            'display_name' => 'GEMET Thesaurus',
            'is_active' => false,
            'is_elmo_active' => true,
        ]
    );

    getJson('/api/v1/vocabularies/gemet', ['X-API-Key' => 'test-api-key'])
        ->assertOk();
});
