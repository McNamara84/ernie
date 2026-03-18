<?php

declare(strict_types=1);

use App\Http\Controllers\DescriptionTypeController;
use App\Models\DescriptionType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(DescriptionTypeController::class);

beforeEach(function () {
    DescriptionType::create(['name' => 'Abstract', 'slug' => 'Abstract', 'is_active' => true, 'is_elmo_active' => true]);
    DescriptionType::create(['name' => 'Methods', 'slug' => 'Methods', 'is_active' => true, 'is_elmo_active' => false]);
    DescriptionType::create(['name' => 'Other', 'slug' => 'Other', 'is_active' => false, 'is_elmo_active' => false]);
});

describe('index', function () {
    it('returns all description types', function () {
        $response = $this->getJson('/api/v1/description-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns description types ordered by name', function () {
        $response = $this->getJson('/api/v1/description-types');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['Abstract', 'Methods', 'Other']);
    });

    it('returns id, name and slug fields', function () {
        $response = $this->getJson('/api/v1/description-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active description types with valid API key', function () {
        $response = $this->getJson('/api/v1/description-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['name'])->toBe('Abstract');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/description-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active description types', function () {
        $response = $this->getJson('/api/v1/description-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive description types', function () {
        $response = $this->getJson('/api/v1/description-types/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Other');
    });
});
