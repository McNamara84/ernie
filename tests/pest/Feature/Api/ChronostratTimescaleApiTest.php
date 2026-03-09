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

function createTestChronostratVocabularyFile(): void
{
    $testData = [
        'lastUpdated' => '2026-03-09 12:00:00',
        'data' => [
            [
                'id' => 'http://resource.geosciml.org/classifier/ics/ischart/Phanerozoic',
                'text' => 'Phanerozoic',
                'language' => 'en',
                'scheme' => 'International Chronostratigraphic Chart',
                'schemeURI' => 'http://resource.geosciml.org/vocabulary/timescale/gts2020',
                'description' => '',
                'children' => [
                    [
                        'id' => 'http://resource.geosciml.org/classifier/ics/ischart/Mesozoic',
                        'text' => 'Mesozoic',
                        'language' => 'en',
                        'scheme' => 'International Chronostratigraphic Chart',
                        'schemeURI' => 'http://resource.geosciml.org/vocabulary/timescale/gts2020',
                        'description' => '',
                        'children' => [],
                    ],
                ],
            ],
        ],
    ];

    Storage::put('chronostrat-timescale.json', json_encode($testData));
}

it('returns chronostrat vocabulary via API with valid key', function (): void {
    createTestChronostratVocabularyFile();

    $response = getJson('/api/v1/vocabularies/chronostrat-timescale', ['X-API-Key' => 'test-api-key'])
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

    expect($response->json('lastUpdated'))->toBe('2026-03-09 12:00:00');
    expect($response->json('data.0.text'))->toBe('Phanerozoic');
    expect($response->json('data.0.children.0.text'))->toBe('Mesozoic');
});

it('returns 404 when vocabulary file does not exist', function (): void {
    getJson('/api/v1/vocabularies/chronostrat-timescale', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson([
            'error' => 'Vocabulary file not found. Please run: php artisan get-chronostrat-timescale',
        ]);
});

it('rejects requests without an API key', function (): void {
    createTestChronostratVocabularyFile();

    getJson('/api/v1/vocabularies/chronostrat-timescale')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function (): void {
    createTestChronostratVocabularyFile();

    getJson('/api/v1/vocabularies/chronostrat-timescale', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 404 when thesaurus is disabled for ELMO', function (): void {
    createTestChronostratVocabularyFile();

    // Disable chronostrat for ELMO
    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
        [
            'display_name' => 'ICS Chronostratigraphy',
            'is_active' => true,
            'is_elmo_active' => false,
        ]
    );

    // API request uses ernie.api-key middleware → treated as ELMO → checks is_elmo_active
    getJson('/api/v1/vocabularies/chronostrat-timescale', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson(['error' => 'Thesaurus is disabled']);
});

it('returns data when thesaurus is active for ELMO', function (): void {
    createTestChronostratVocabularyFile();

    ThesaurusSetting::updateOrCreate(
        ['type' => ThesaurusSetting::TYPE_CHRONOSTRAT],
        [
            'display_name' => 'ICS Chronostratigraphy',
            'is_active' => false,
            'is_elmo_active' => true,
        ]
    );

    // API request (ELMO) → checks is_elmo_active which is true
    getJson('/api/v1/vocabularies/chronostrat-timescale', ['X-API-Key' => 'test-api-key'])
        ->assertOk();
});
