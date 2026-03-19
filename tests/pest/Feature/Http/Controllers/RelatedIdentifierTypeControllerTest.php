<?php

declare(strict_types=1);

use App\Http\Controllers\RelatedIdentifierTypeController;
use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(RelatedIdentifierTypeController::class);

beforeEach(function () {
    $doi = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'is_active' => true, 'is_elmo_active' => true]);
    $url = IdentifierType::create(['name' => 'URL', 'slug' => 'URL', 'is_active' => true, 'is_elmo_active' => false]);
    IdentifierType::create(['name' => 'ARK', 'slug' => 'ARK', 'is_active' => false, 'is_elmo_active' => false]);

    // Add patterns to DOI type
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'pattern' => '^10\.\d{4,}/.+$',
        'type' => 'validation',
        'priority' => 10,
        'is_active' => true,
    ]);
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'pattern' => '10\.\d{4,}/',
        'type' => 'detection',
        'priority' => 10,
        'is_active' => true,
    ]);
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'pattern' => 'inactive-pattern',
        'type' => 'validation',
        'priority' => 1,
        'is_active' => false,
    ]);
});

describe('index', function () {
    it('returns all identifier types with patterns', function () {
        $response = $this->getJson('/api/v1/identifier-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('groups patterns by type', function () {
        $response = $this->getJson('/api/v1/identifier-types');

        $doi = collect($response->json())->firstWhere('slug', 'DOI');
        expect($doi['patterns'])->toHaveKeys(['validation', 'detection']);
    });

    it('only includes active patterns', function () {
        $response = $this->getJson('/api/v1/identifier-types');

        $doi = collect($response->json())->firstWhere('slug', 'DOI');
        expect($doi['patterns']['validation'])->toHaveCount(1);
        expect($doi['patterns']['detection'])->toHaveCount(1);
    });

    it('returns id, name, slug and patterns fields', function () {
        $response = $this->getJson('/api/v1/identifier-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug', 'patterns' => ['validation', 'detection']],
        ]);
    });
});

describe('elmo', function () {
    it('returns only elmo-active identifier types with valid API key', function () {
        $response = $this->getJson('/api/v1/identifier-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['slug'])->toBe('DOI');
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/identifier-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active identifier types', function () {
        $response = $this->getJson('/api/v1/identifier-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive identifier types', function () {
        $response = $this->getJson('/api/v1/identifier-types/ernie');

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->not->toContain('ARK');
    });
});
