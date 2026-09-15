<?php

declare(strict_types=1);

use App\Models\IdentifierType;
use App\Models\IdentifierTypePattern;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.ernie.api_key' => 'test-api-key']);

    $doi = IdentifierType::create(['name' => 'DOI', 'slug' => 'DOI', 'description' => 'A character string used to uniquely identify an object.', 'is_active' => true, 'is_elmo_active' => true]);
    $url = IdentifierType::create(['name' => 'URL', 'slug' => 'URL', 'description' => 'Also known as web address.', 'is_active' => true, 'is_elmo_active' => false]);
    IdentifierType::create(['name' => 'Handle', 'slug' => 'Handle', 'description' => 'An ID in the Handle system.', 'is_active' => false, 'is_elmo_active' => true]);
    IdentifierType::create(['name' => 'Custom Identifier', 'slug' => 'CustomIdentifier', 'description' => null, 'is_active' => false, 'is_elmo_active' => false]);

    // Add patterns for DOI
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'type' => 'validation',
        'pattern' => '^10\.\d{4,}\/\S+$',
        'is_active' => true,
        'priority' => 10,
    ]);
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'type' => 'detection',
        'pattern' => '^https?:\/\/(?:doi\.org|dx\.doi\.org)\/(.+)',
        'is_active' => true,
        'priority' => 20,
    ]);
    IdentifierTypePattern::create([
        'identifier_type_id' => $doi->id,
        'type' => 'detection',
        'pattern' => '^doi:',
        'is_active' => false,
        'priority' => 5,
    ]);

    // URL has no patterns
    // Handle and the custom identifier type are inactive
});

describe('GET /api/v1/identifier-types', function (): void {
    test('returns all identifier types ordered by name', function (): void {
        $response = $this->getJson('/api/v1/identifier-types')->assertOk();

        expect($response->json())->toHaveCount(4);
    });

    test('returns correct structure with patterns', function (): void {
        $this->getJson('/api/v1/identifier-types')
            ->assertOk()
            ->assertJsonStructure([['id', 'name', 'slug', 'description', 'patterns' => ['validation', 'detection']]])
            ->assertJsonFragment([
                'slug' => 'DOI',
                'description' => 'A character string used to uniquely identify an object.',
            ])
            ->assertJsonFragment([
                'slug' => 'CustomIdentifier',
                'description' => null,
            ]);
    });

    test('returns only active patterns', function (): void {
        $response = $this->getJson('/api/v1/identifier-types')->assertOk();

        $doi = collect($response->json())->firstWhere('slug', 'DOI');
        // 1 validation pattern + 1 active detection pattern (inactive one filtered out)
        expect($doi['patterns']['validation'])->toHaveCount(1)
            ->and($doi['patterns']['detection'])->toHaveCount(1);
    });
});

describe('GET /api/v1/identifier-types/ernie', function (): void {
    test('returns only active identifier types', function (): void {
        $response = $this->getJson('/api/v1/identifier-types/ernie')->assertOk();

        expect($response->json())->toHaveCount(2);

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->toContain('DOI', 'URL')
            ->not->toContain('Handle', 'CustomIdentifier');

        expect(collect($response->json())->firstWhere('slug', 'URL')['description'])
            ->toBe('Also known as web address.');
    });
});

describe('GET /api/v1/identifier-types/elmo', function (): void {
    test('returns only active and elmo-active identifier types with valid API key', function (): void {
        $response = $this->getJson('/api/v1/identifier-types/elmo', [
            'X-API-Key' => 'test-api-key',
        ])->assertOk();

        expect($response->json())->toHaveCount(1);

        $slugs = collect($response->json())->pluck('slug')->all();
        expect($slugs)->toContain('DOI')
            ->not->toContain('URL', 'Handle', 'CustomIdentifier');

        expect($response->json('0.description'))->toBe('A character string used to uniquely identify an object.');
    });

    test('includes patterns in response', function (): void {
        $response = $this->getJson('/api/v1/identifier-types/elmo', [
            'X-API-Key' => 'test-api-key',
        ])->assertOk();

        $doi = $response->json('0');
        expect($doi['patterns']['validation'])->toHaveCount(1)
            ->and($doi['patterns']['validation'][0]['pattern'])->toBe('^10\.\d{4,}\/\S+$')
            ->and($doi['patterns']['detection'])->toHaveCount(1);
    });

    test('rejects request without API key', function (): void {
        $this->getJson('/api/v1/identifier-types/elmo')->assertUnauthorized();
    });

    test('rejects request with invalid API key', function (): void {
        $this->getJson('/api/v1/identifier-types/elmo', [
            'X-API-Key' => 'wrong-key',
        ])->assertUnauthorized();
    });
});
