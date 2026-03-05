<?php

declare(strict_types=1);

use App\Models\PidSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.ernie.api_key' => 'test-api-key']);
    Storage::fake();
});

function createRorSetting(bool $isActive = true, bool $isElmoActive = true): PidSetting
{
    $setting = PidSetting::firstOrCreate(
        ['type' => PidSetting::TYPE_ROR],
        [
            'display_name' => 'ROR (Research Organization Registry)',
            'is_active' => $isActive,
            'is_elmo_active' => $isElmoActive,
        ]
    );

    $setting->update([
        'is_active' => $isActive,
        'is_elmo_active' => $isElmoActive,
    ]);

    return $setting->fresh();
}

function storeRorData(array $data = []): void
{
    $content = json_encode(array_merge([
        'lastUpdated' => '2025-06-01T10:00:00Z',
        'data' => [
            ['prefLabel' => 'GFZ Potsdam', 'rorId' => 'https://ror.org/04z8jg394', 'otherLabel' => 'Helmholtz-Zentrum Potsdam'],
            ['prefLabel' => 'MIT', 'rorId' => 'https://ror.org/042nb2s44', 'otherLabel' => 'Massachusetts Institute of Technology'],
        ],
        'total' => 2,
    ], $data));

    Storage::put('ror/ror-affiliations.json', $content);
}

it('returns ROR affiliations with valid API key', function () {
    createRorSetting();
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonStructure(['lastUpdated', 'total', 'data'])
        ->assertJsonPath('total', 2)
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.prefLabel', 'GFZ Potsdam')
        ->assertJsonPath('data.0.rorId', 'https://ror.org/04z8jg394');
});

it('rejects requests without an API key', function () {
    createRorSetting();
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects requests with an invalid API key', function () {
    createRorSetting();
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 404 when ROR is disabled via is_elmo_active', function () {
    createRorSetting(isActive: true, isElmoActive: false);
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson(['error' => 'ROR is disabled']);
});

it('returns 404 when ROR data file does not exist', function () {
    createRorSetting();
    // Don't store any data

    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJsonPath('error', 'Vocabulary file not found. Please run: php artisan get-ror-ids');
});

it('returns 404 when no PidSetting record exists (defaults to active but no file)', function () {
    // No PidSetting record → isPidActive defaults to true, but no file exists
    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404);
});

it('rejects API keys in query parameters for security', function () {
    createRorSetting();
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns raw JSON without re-encoding', function () {
    createRorSetting();
    storeRorData();

    getJson('/api/v1/ror-affiliations/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonPath('lastUpdated', '2025-06-01T10:00:00Z');
});
