<?php

use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.elmo.api_key' => 'test-api-key']);
});

function createElmoLanguages(): Language
{
    $enabled = Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::create(['code' => 'de', 'name' => 'German', 'active' => true, 'elmo_active' => false]);

    return $enabled;
}

it('lists all languages', function () {
    Language::create(['code' => 'en', 'name' => 'English']);

    $this->getJson('/api/v1/languages')
        ->assertOk()
        ->assertJsonCount(1);
});

it('lists ELMO-active languages', function () {
    $enabled = createElmoLanguages();

    $this->getJson('/api/v1/languages/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', $enabled->code);
});

it('lists ERNIE-active languages', function () {
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true]);
    Language::create(['code' => 'de', 'name' => 'German', 'active' => false]);

    $this->getJson('/api/v1/languages/ernie')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', 'en');
});

it('rejects language requests without an API key when one is configured', function () {
    createElmoLanguages();

    $this->getJson('/api/v1/languages/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects language requests with an invalid API key', function () {
    createElmoLanguages();

    $this->getJson('/api/v1/languages/elmo', ['X-API-Key' => 'wrong-key'])
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('allows language requests with a valid API key header', function () {
    $enabled = createElmoLanguages();

    $this->getJson('/api/v1/languages/elmo', ['X-API-Key' => 'test-api-key'])
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.code', $enabled->code);
});

it('rejects API keys in query parameters for security', function () {
    createElmoLanguages();

    // API keys in query params are rejected as they can leak via logs and Referer headers
    $this->getJson('/api/v1/languages/elmo?api_key=test-api-key')
        ->assertStatus(401)
        ->assertJson(['message' => 'Invalid API key.']);
});

it('rejects language requests when no API key is configured on server', function () {
    createElmoLanguages();

    config(['services.elmo.api_key' => null]);

    $this->getJson('/api/v1/languages/elmo')
        ->assertStatus(401)
        ->assertJson(['message' => 'API key not configured.']);
});
