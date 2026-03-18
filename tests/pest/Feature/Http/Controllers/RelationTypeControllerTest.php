<?php

declare(strict_types=1);

use App\Http\Controllers\RelationTypeController;
use App\Models\RelationType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(RelationTypeController::class);

beforeEach(function () {
    RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true, 'is_elmo_active' => true]);
    RelationType::create(['name' => 'Is Part Of', 'slug' => 'IsPartOf', 'is_active' => true, 'is_elmo_active' => false]);
    RelationType::create(['name' => 'Other', 'slug' => 'Other', 'is_active' => false, 'is_elmo_active' => false]);
});

describe('index', function () {
    it('returns all relation types', function () {
        $response = $this->getJson('/api/v1/relation-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns relation types ordered by name', function () {
        $response = $this->getJson('/api/v1/relation-types');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['Cites', 'Is Part Of', 'Other']);
    });

    it('returns id, name and slug fields', function () {
        $response = $this->getJson('/api/v1/relation-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active relation types with valid API key', function () {
        $response = $this->getJson('/api/v1/relation-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['slug'])->toBe('Cites');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/relation-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active relation types', function () {
        $response = $this->getJson('/api/v1/relation-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive relation types', function () {
        $response = $this->getJson('/api/v1/relation-types/ernie');

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->not->toContain('Other');
    });
});
