<?php

declare(strict_types=1);

use App\Http\Controllers\RoleController;
use App\Models\ContributorType;

covers(RoleController::class);

beforeEach(function () {
    // Create some contributor types for testing
    ContributorType::create(['name' => 'ContactPerson', 'slug' => 'contact-person', 'is_active' => true]);
    ContributorType::create(['name' => 'DataCurator', 'slug' => 'data-curator', 'is_active' => true]);
    ContributorType::create(['name' => 'ProjectLeader', 'slug' => 'project-leader', 'is_active' => true]);
});

describe('author roles', function () {
    it('returns contributor types for Ernie authors', function () {
        $response = $this->getJson('/api/v1/roles/authors/ernie');

        $response->assertOk()
            ->assertJsonCount(3)
            ->assertJsonStructure([['id', 'name', 'slug']]);
    });

    it('returns contributor types ordered by name', function () {
        $response = $this->getJson('/api/v1/roles/authors/ernie');
        $names = collect($response->json())->pluck('name')->all();

        expect($names)->toBe(['ContactPerson', 'DataCurator', 'ProjectLeader']);
    });

    it('requires API key for ELMO author roles', function () {
        $this->getJson('/api/v1/roles/authors/elmo')
            ->assertUnauthorized();
    });

    it('returns ELMO author roles with valid API key', function () {
        $this->getJson('/api/v1/roles/authors/elmo', ['X-API-Key' => config('services.ernie.api_key')])
            ->assertOk()
            ->assertJsonCount(3);
    });
});

describe('contributor person roles', function () {
    it('returns contributor types for Ernie contributor persons', function () {
        $this->getJson('/api/v1/roles/contributor-persons/ernie')
            ->assertOk()
            ->assertJsonCount(3);
    });

    it('requires API key for ELMO contributor person roles', function () {
        $this->getJson('/api/v1/roles/contributor-persons/elmo')
            ->assertUnauthorized();
    });
});

describe('contributor institution roles', function () {
    it('returns contributor types for Ernie contributor institutions', function () {
        $this->getJson('/api/v1/roles/contributor-institutions/ernie')
            ->assertOk()
            ->assertJsonCount(3);
    });

    it('requires API key for ELMO contributor institution roles', function () {
        $this->getJson('/api/v1/roles/contributor-institutions/elmo')
            ->assertUnauthorized();
    });
});
