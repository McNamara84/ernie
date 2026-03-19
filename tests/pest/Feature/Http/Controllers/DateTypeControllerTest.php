<?php

declare(strict_types=1);

use App\Http\Controllers\DateTypeController;
use App\Models\DateType;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
covers(DateTypeController::class);

beforeEach(function () {
    DateType::factory()->create(['name' => 'Created', 'slug' => 'Created', 'is_active' => true]);
    DateType::factory()->create(['name' => 'Collected', 'slug' => 'Collected', 'is_active' => true]);
    DateType::factory()->create(['name' => 'Withdrawn', 'slug' => 'Withdrawn', 'is_active' => false]);
});

describe('index', function () {
    it('returns all date types', function () {
        $response = $this->getJson('/api/v1/date-types');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    it('returns date types ordered by name', function () {
        $response = $this->getJson('/api/v1/date-types');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->toBe(['Collected', 'Created', 'Withdrawn']);
    });

    it('returns id, name and slug fields', function () {
        $response = $this->getJson('/api/v1/date-types');

        $response->assertJsonStructure([
            ['id', 'name', 'slug'],
        ]);
    });
});

describe('elmo', function () {
    it('returns only active date types with valid API key', function () {
        $response = $this->getJson('/api/v1/date-types/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('rejects requests without API key', function () {
        $response = $this->getJson('/api/v1/date-types/elmo');

        $response->assertUnauthorized();
    });
});

describe('ernie', function () {
    it('returns only active date types', function () {
        $response = $this->getJson('/api/v1/date-types/ernie');

        $response->assertOk()
            ->assertJsonCount(2);
    });

    it('excludes inactive date types', function () {
        $response = $this->getJson('/api/v1/date-types/ernie');

        $names = collect($response->json())->pluck('name')->all();
        expect($names)->not->toContain('Withdrawn');
    });
});
