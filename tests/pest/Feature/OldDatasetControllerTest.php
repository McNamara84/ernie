<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;

beforeEach(function () {
    $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

describe('authorization', function () {
    test('requires authentication', function () {
        $this->get('/old-datasets')
            ->assertRedirect(route('login'));
    });

    test('requires access-old-datasets gate', function () {
        // Only ADMIN has access-old-datasets
        $this->actingAs($this->curator)
            ->get('/old-datasets')
            ->assertForbidden();
    });

    test('admin can access old datasets', function () {
        // The page needs the metaworks connection, so we expect either
        // a success or a 500 error (if the metaworks DB is not configured)
        $response = $this->actingAs($this->admin)
            ->get('/old-datasets');

        // In test env without metaworks DB, this might fail with 500
        // but it should NOT be 403 (authorization should pass)
        expect($response->status())->not->toBe(403);
    });
});

describe('filter options', function () {
    test('filter options require authentication', function () {
        $this->getJson('/old-datasets/filter-options')
            ->assertUnauthorized();
    });
});

describe('detail endpoints authorization', function () {
    test('authors endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/authors')
            ->assertUnauthorized();
    });

    test('contributors endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/contributors')
            ->assertUnauthorized();
    });

    test('descriptions endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/descriptions')
            ->assertUnauthorized();
    });

    test('funding references endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/funding-references')
            ->assertUnauthorized();
    });

    test('dates endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/dates')
            ->assertUnauthorized();
    });

    test('controlled keywords endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/controlled-keywords')
            ->assertUnauthorized();
    });

    test('free keywords endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/free-keywords')
            ->assertUnauthorized();
    });

    test('msl keywords endpoint requires authentication', function () {
        $this->getJson('/old-datasets/1/msl-keywords')
            ->assertUnauthorized();
    });
});
