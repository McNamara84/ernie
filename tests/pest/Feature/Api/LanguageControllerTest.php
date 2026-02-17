<?php

declare(strict_types=1);

use App\Models\Language;

beforeEach(function () {
    Language::create(['code' => 'en', 'name' => 'English', 'active' => true, 'elmo_active' => true]);
    Language::create(['code' => 'de', 'name' => 'German', 'active' => true, 'elmo_active' => false]);
    Language::create(['code' => 'fr', 'name' => 'French', 'active' => false, 'elmo_active' => false]);
});

describe('index', function () {
    test('returns all languages', function () {
        $response = $this->getJson('/api/v1/languages');

        $response->assertOk()
            ->assertJsonCount(3);
    });

    test('returns languages ordered by name', function () {
        $response = $this->getJson('/api/v1/languages');

        $names = collect($response->json())->pluck('name')->toArray();

        expect($names)->toBe(['English', 'French', 'German']);
    });

    test('returns id, code, and name fields', function () {
        $response = $this->getJson('/api/v1/languages');

        $first = $response->json()[0];

        expect($first)->toHaveKeys(['id', 'code', 'name']);
    });
});

describe('ernie', function () {
    test('returns only active languages', function () {
        $response = $this->getJson('/api/v1/languages/ernie');

        $response->assertOk()
            ->assertJsonCount(2);

        $codes = collect($response->json())->pluck('code')->toArray();
        expect($codes)->not->toContain('fr');
    });
});

describe('elmo', function () {
    test('returns only active and elmo-active languages', function () {
        $response = $this->getJson('/api/v1/languages/elmo', [
            'X-API-Key' => config('services.ernie.api_key'),
        ]);

        $response->assertOk()
            ->assertJsonCount(1);

        expect($response->json()[0]['code'])->toBe('en');
    });
});
