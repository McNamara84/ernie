<?php

declare(strict_types=1);

use App\Http\Controllers\TitleTypeController;
use App\Models\TitleType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(TitleTypeController::class);

beforeEach(function () {
    TitleType::factory()->create(['name' => 'Main Title', 'slug' => 'MainTitle', 'is_active' => true, 'is_elmo_active' => true]);
    TitleType::factory()->create(['name' => 'Subtitle', 'slug' => 'Subtitle', 'is_active' => true, 'is_elmo_active' => false]);
    TitleType::factory()->create(['name' => 'Other', 'slug' => 'Other', 'is_active' => false, 'is_elmo_active' => false]);
});

describe('index', function () {
    it('returns all title types', function () {
        $response = $this->getJson('/api/v1/title-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns slug in kebab-case', function () {
        $response = $this->getJson('/api/v1/title-types');

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->toContain('main-title');
    });

    it('returns id, name and slug fields', function () {
        $response = $this->getJson('/api/v1/title-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active title types with valid API key', function () {
        $response = $this->getJson('/api/v1/title-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['name'])->toBe('Main Title');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/title-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active title types', function () {
        $response = $this->getJson('/api/v1/title-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive title types', function () {
        $response = $this->getJson('/api/v1/title-types/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Other');
    });
});
