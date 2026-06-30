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

function createRaidSettingForApi(bool $isActive = true, bool $isElmoActive = true): PidSetting
{
    $setting = PidSetting::firstOrCreate(
        ['type' => PidSetting::TYPE_RAID],
        [
            'display_name' => 'RAiD (Research Activity Identifier)',
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

function storeRaidProjects(array $data = []): void
{
    Storage::put('raid/raid-projects.json', json_encode(array_merge([
        'lastUpdated' => '2026-06-26T10:00:00Z',
        'total' => 1,
        'data' => [
            [
                'raidId' => 'https://raid.org/10.71613/alpha',
                'title' => 'Alpha RAiD Project',
                'description' => 'A public research activity',
            ],
        ],
    ], $data), JSON_THROW_ON_ERROR));
}

it('returns RAiD projects with a valid API key', function () {
    createRaidSettingForApi();
    storeRaidProjects();

    getJson('/api/v1/vocabularies/raid-projects', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonStructure(['lastUpdated', 'total', 'data'])
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.raidId', 'https://raid.org/10.71613/alpha')
        ->assertJsonPath('data.0.title', 'Alpha RAiD Project');
});

it('rejects RAiD project requests without an API key', function () {
    createRaidSettingForApi();
    storeRaidProjects();

    getJson('/api/v1/vocabularies/raid-projects')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('returns 404 when RAiD is disabled via is_elmo_active', function () {
    createRaidSettingForApi(isActive: true, isElmoActive: false);
    storeRaidProjects();

    getJson('/api/v1/vocabularies/raid-projects', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJson(['error' => 'RAiD is disabled']);
});

it('returns 404 when RAiD data file is missing', function () {
    createRaidSettingForApi();

    getJson('/api/v1/vocabularies/raid-projects', ['X-API-Key' => 'test-api-key'])
        ->assertStatus(404)
        ->assertJsonPath('error', 'Vocabulary file not found. Please run: php artisan get-raid-projects');
});
