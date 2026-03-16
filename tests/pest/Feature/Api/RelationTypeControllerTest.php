<?php

declare(strict_types=1);

use App\Models\RelationType;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);

    RelationType::create(['name' => 'Cites', 'slug' => 'Cites', 'is_active' => true, 'is_elmo_active' => true]);
    RelationType::create(['name' => 'IsCitedBy', 'slug' => 'IsCitedBy', 'is_active' => true, 'is_elmo_active' => false]);
    RelationType::create(['name' => 'References', 'slug' => 'References', 'is_active' => false, 'is_elmo_active' => true]);
    RelationType::create(['name' => 'IsReferencedBy', 'slug' => 'IsReferencedBy', 'is_active' => false, 'is_elmo_active' => false]);
});

describe('GET /api/v1/relation-types', function (): void {
    test('returns all relation types ordered by name', function (): void {
        $response = $this->getJson('/api/v1/relation-types')->assertOk();

        expect($response->json())->toHaveCount(4)
            ->and($response->json('0.name'))->toBe('Cites');
    });

    test('returns correct structure', function (): void {
        $this->getJson('/api/v1/relation-types')
            ->assertOk()
            ->assertJsonStructure([['id', 'name', 'slug']]);
    });
});

describe('GET /api/v1/relation-types/ernie', function (): void {
    test('returns only active relation types', function (): void {
        $response = $this->getJson('/api/v1/relation-types/ernie')->assertOk();

        expect($response->json())->toHaveCount(2);

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->toContain('Cites', 'IsCitedBy')
            ->not->toContain('References', 'IsReferencedBy');
    });
});

describe('GET /api/v1/relation-types/elmo', function (): void {
    test('returns only active and elmo-active relation types with valid API key', function (): void {
        $response = $this->getJson('/api/v1/relation-types/elmo', [
            'X-API-Key' => 'test-api-key',
        ])->assertOk();

        expect($response->json())->toHaveCount(1);

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->toContain('Cites')
            ->not->toContain('IsCitedBy', 'References', 'IsReferencedBy');
    });

    test('rejects request without API key', function (): void {
        $this->getJson('/api/v1/relation-types/elmo')->assertUnauthorized();
    });

    test('rejects request with invalid API key', function (): void {
        $this->getJson('/api/v1/relation-types/elmo', [
            'X-API-Key' => 'wrong-key',
        ])->assertUnauthorized();
    });
});
