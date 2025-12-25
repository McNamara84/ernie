<?php

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

describe('DataCiteImportController', function () {
    describe('authorization', function () {
        it('allows admin to start import', function () {
            $response = $this->actingAs($this->adminUser)
                ->postJson('/datacite/import/start');

            // Should not be 403 (may be other error due to missing config)
            expect($response->status())->not->toBe(403);
        });

        it('allows group leader to start import', function () {
            $response = $this->actingAs($this->groupLeader)
                ->postJson('/datacite/import/start');

            expect($response->status())->not->toBe(403);
        });

        it('denies curator from starting import', function () {
            $response = $this->actingAs($this->curator)
                ->postJson('/datacite/import/start');

            expect($response->status())->toBe(403);
        });

        it('denies beginner from starting import', function () {
            $response = $this->actingAs($this->beginner)
                ->postJson('/datacite/import/start');

            expect($response->status())->toBe(403);
        });

        it('requires authentication', function () {
            $response = $this->postJson('/datacite/import/start');

            // Either 401 or redirect (302)
            expect(in_array($response->status(), [401, 302]))->toBeTrue();
        });
    });

    describe('status endpoint', function () {
        it('returns import status from cache', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';

            // Set up cache with test data
            cache()->put("datacite_import:{$importId}", [
                'status' => 'running',
                'total' => 100,
                'processed' => 50,
                'imported' => 45,
                'skipped' => 3,
                'failed' => 2,
                'skipped_dois' => ['10.5880/skip.1'],
                'failed_dois' => [['doi' => '10.5880/fail.1', 'error' => 'Test error']],
                'started_at' => now()->toIso8601String(),
            ], 3600);

            $response = $this->actingAs($this->adminUser)
                ->getJson("/datacite/import/{$importId}/status");

            $response->assertOk();
            $response->assertJson([
                'status' => 'running',
                'total' => 100,
                'processed' => 50,
            ]);
        });

        it('returns 404 for non-existent import', function () {
            $response = $this->actingAs($this->adminUser)
                ->getJson('/datacite/import/a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5e/status');

            $response->assertNotFound();
        });
    });

    describe('cancel endpoint', function () {
        it('sets import status to cancelled', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5f';

            cache()->put("datacite_import:{$importId}", [
                'status' => 'running',
                'total' => 100,
                'processed' => 50,
            ], 3600);

            $response = $this->actingAs($this->adminUser)
                ->postJson("/datacite/import/{$importId}/cancel");

            $response->assertOk();

            $status = cache()->get("datacite_import:{$importId}");
            expect($status['status'])->toBe('cancelled');
        });

        it('requires admin or group leader to cancel', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c60';

            cache()->put("datacite_import:{$importId}", [
                'status' => 'running',
            ], 3600);

            $response = $this->actingAs($this->curator)
                ->postJson("/datacite/import/{$importId}/cancel");

            expect($response->status())->toBe(403);
        });
    });
});

describe('ResourceController canImportFromDataCite', function () {
    it('returns true for admin users', function () {
        $response = $this->actingAs($this->adminUser)
            ->get('/resources');

        $response->assertInertia(fn ($page) => $page
            ->has('canImportFromDataCite')
            ->where('canImportFromDataCite', true)
        );
    });

    it('returns true for group leader users', function () {
        $response = $this->actingAs($this->groupLeader)
            ->get('/resources');

        $response->assertInertia(fn ($page) => $page
            ->where('canImportFromDataCite', true)
        );
    });

    it('returns false for curator users', function () {
        $response = $this->actingAs($this->curator)
            ->get('/resources');

        $response->assertInertia(fn ($page) => $page
            ->where('canImportFromDataCite', false)
        );
    });

    it('returns false for beginner users', function () {
        $response = $this->actingAs($this->beginner)
            ->get('/resources');

        $response->assertInertia(fn ($page) => $page
            ->where('canImportFromDataCite', false)
        );
    });
});

describe('ResourceController destroy authorization', function () {
    it('allows admin to delete resources', function () {
        $resource = \App\Models\Resource::factory()->create();

        $response = $this->actingAs($this->adminUser)
            ->delete("/resources/{$resource->id}");

        $response->assertRedirect('/resources');
        expect(\App\Models\Resource::find($resource->id))->toBeNull();
    });

    it('allows group leader to delete resources', function () {
        $resource = \App\Models\Resource::factory()->create();

        $response = $this->actingAs($this->groupLeader)
            ->delete("/resources/{$resource->id}");

        $response->assertRedirect('/resources');
        expect(\App\Models\Resource::find($resource->id))->toBeNull();
    });

    it('denies curator from deleting resources', function () {
        $resource = \App\Models\Resource::factory()->create();

        $response = $this->actingAs($this->curator)
            ->delete("/resources/{$resource->id}");

        $response->assertForbidden();
        // Resource should still exist
        expect(\App\Models\Resource::find($resource->id))->not->toBeNull();
    });

    it('denies beginner from deleting resources', function () {
        $resource = \App\Models\Resource::factory()->create();

        $response = $this->actingAs($this->beginner)
            ->delete("/resources/{$resource->id}");

        $response->assertForbidden();
        // Resource should still exist
        expect(\App\Models\Resource::find($resource->id))->not->toBeNull();
    });
});
