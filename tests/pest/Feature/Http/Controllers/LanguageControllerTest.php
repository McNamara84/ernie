<?php

declare(strict_types=1);

use App\Http\Controllers\LanguageController;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(LanguageController::class);

beforeEach(function () {
    Language::factory()->create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::factory()->create(['code' => 'de', 'name' => 'German', 'active' => true, 'elmo_active' => false]);
    Language::factory()->create(['code' => 'la', 'name' => 'Latin', 'active' => false, 'elmo_active' => false]);
});

describe('index', function () {
    it('returns all languages', function () {
        $response = $this->getJson('/api/v1/languages');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns languages ordered by name', function () {
        $response = $this->getJson('/api/v1/languages');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['English', 'German', 'Latin']);
    });

    it('returns id, code and name fields', function () {
        $response = $this->getJson('/api/v1/languages');

        $response->assertJsonStructure([
            ['id', 'code', 'name'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active languages with valid API key', function () {
        $response = $this->getJson('/api/v1/languages/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['code'])->toBe('en');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/languages/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active languages', function () {
        $response = $this->getJson('/api/v1/languages/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive languages', function () {
        $response = $this->getJson('/api/v1/languages/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Latin');
    });
});
