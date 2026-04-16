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

function createTestEuroSciVocVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2026-04-16T10:00:00+00:00',
        'data' => [
            [
                'id' => 'http://data.europa.eu/8mn/euroscivoc/concept-1',
                'text' => 'natural sciences',
                'language' => 'en',
                'scheme' => 'European Science Vocabulary (EuroSciVoc)',
                'schemeURI' => 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
                'description' => '',
                'children' => [
                    [
                        'id' => 'http://data.europa.eu/8mn/euroscivoc/concept-2',
                        'text' => 'physical sciences',
                        'language' => 'en',
                        'scheme' => 'European Science Vocabulary (EuroSciVoc)',
                        'schemeURI' => 'http://data.europa.eu/8mn/euroscivoc/40c0f173-baa3-48a3-9fe6-d6e8fb366a00',
                        'description' => '',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('euroscivoc.json', json_encode($testData));
}

it('returns EuroSciVoc vocabulary via API with valid key', function (): void {
    createTestEuroSciVocVocabularyFile();

    $response = getJson('/api/v1/vocabularies/euroscivoc', ['X-API-Key' => 'test-api-key'])
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

    expect($response->json('lastUpdated'))->toBe('2026-04-16T10:00:00+00:00');
    expect($response->json('data.0.text'))->toBe('natural sciences');
    expect($response->json('data.0.children.0.text'))->toBe('physical sciences');
});

it('returns 404 when vocabulary file does not exist', function (): void {
    getJson('/api/v1/vocabularies/euroscivoc', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-euroscivoc',
        ]);
});

it('rejects requests without an API key', function (): void {
    createTestEuroSciVocVocabularyFile();

    getJson('/api/v1/vocabularies/euroscivoc')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function (): void {
    createTestEuroSciVocVocabularyFile();

    getJson('/api/v1/vocabularies/euroscivoc', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 404 when thesaurus is disabled for ELMO', function (): void {
    createTestEuroSciVocVocabularyFile();

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
        [
            'display_name' => 'European Science Vocabulary (EuroSciVoc)',
            'is_active' => true,
            'is_elmo_active' => false,
        ]
    );

    getJson('/api/v1/vocabularies/euroscivoc', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson(['error' => 'Thesaurus is disabled']);
});

it('returns data when thesaurus is active for ELMO', function (): void {
    createTestEuroSciVocVocabularyFile();

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_EUROSCIVOC],
        [
            'display_name' => 'European Science Vocabulary (EuroSciVoc)',
            'is_active' => false,
            'is_elmo_active' => true,
        ]
    );

    getJson('/api/v1/vocabularies/euroscivoc', ['X-API-Key' => 'test-api-key'])
        ->assertOk();
});
