<?php

use App\Enums\UserRole;
use App\Jobs\ImportIgsnsFromDataCiteJob;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->adminUser = User::factory()->create(['role' => UserRole::ADMIN]);
    $this->groupLeader = User::factory()->create(['role' => UserRole::GROUP_LEADER]);
    $this->curator = User::factory()->create(['role' => UserRole::CURATOR]);
    $this->beginner = User::factory()->create(['role' => UserRole::BEGINNER]);
});

describe('IgsnImportController', function () {
    describe('authorization', function () {
        it('allows admin to start IGSN import', function () {
            Queue::fake();

            $response = $this->actingAs($this->adminUser)
                ->postJson('/igsns/import/start');

            expect($response->status())->not->toBe(403);
            Queue::assertPushed(ImportIgsnsFromDataCiteJob::class);
        });

        it('allows group leader to start IGSN import', function () {
            Queue::fake();

            $response = $this->actingAs($this->groupLeader)
                ->postJson('/igsns/import/start');

            expect($response->status())->not->toBe(403);
            Queue::assertPushed(ImportIgsnsFromDataCiteJob::class);
        });

        it('denies curator from starting IGSN import', function () {
            Queue::fake();

            $response = $this->actingAs($this->curator)
                ->postJson('/igsns/import/start');

            expect($response->status())->toBe(403);
            Queue::assertNotPushed(ImportIgsnsFromDataCiteJob::class);
        });

        it('denies beginner from starting IGSN import', function () {
            Queue::fake();

            $response = $this->actingAs($this->beginner)
                ->postJson('/igsns/import/start');

            expect($response->status())->toBe(403);
            Queue::assertNotPushed(ImportIgsnsFromDataCiteJob::class);
        });

        it('requires authentication', function () {
            Queue::fake();

            $response = $this->postJson('/igsns/import/start');

            expect(in_array($response->status(), [401, 302]))->toBeTrue();
            Queue::assertNotPushed(ImportIgsnsFromDataCiteJob::class);
        });
    });

    describe('status endpoint', function () {
        it('returns import status from cache', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d';

            cache()->put("igsn_import:{$importId}", [
                'status' => 'running',
                'total' => 38525,
                'processed' => 5000,
                'imported' => 4800,
                'skipped' => 100,
                'failed' => 100,
                'enriched' => 4500,
                'skipped_dois' => ['10.60510/SKIP001'],
                'failed_dois' => [['doi' => '10.60510/FAIL001', 'error' => 'Test error']],
                'started_at' => now()->toIso8601String(),
            ], 3600);

            $response = $this->actingAs($this->adminUser)
                ->getJson("/igsns/import/{$importId}/status");

            $response->assertOk();
            $response->assertJson([
                'status' => 'running',
                'total' => 38525,
                'processed' => 5000,
                'enriched' => 4500,
            ]);
        });

        it('returns 404 for non-existent import', function () {
            $response = $this->actingAs($this->adminUser)
                ->getJson('/igsns/import/a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5e/status');

            $response->assertNotFound();
        });

        it('returns 400 for invalid UUID format', function () {
            $response = $this->actingAs($this->adminUser)
                ->getJson('/igsns/import/invalid-id/status');

            $response->assertStatus(400);
        });
    });

    describe('cancel endpoint', function () {
        it('sets import status to cancelled', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5f';

            cache()->put("igsn_import:{$importId}", [
                'status' => 'running',
                'total' => 38525,
                'processed' => 5000,
            ], 3600);

            $response = $this->actingAs($this->adminUser)
                ->postJson("/igsns/import/{$importId}/cancel");

            $response->assertOk();

            $status = cache()->get("igsn_import:{$importId}");
            expect($status['status'])->toBe('cancelled');
        });

        it('requires admin or group leader to cancel', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c60';

            cache()->put("igsn_import:{$importId}", [
                'status' => 'running',
            ], 3600);

            $response = $this->actingAs($this->curator)
                ->postJson("/igsns/import/{$importId}/cancel");

            expect($response->status())->toBe(403);
        });

        it('returns 400 when import is already completed', function () {
            $importId = 'a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c61';

            cache()->put("igsn_import:{$importId}", [
                'status' => 'completed',
                'total' => 100,
                'processed' => 100,
            ], 3600);

            $response = $this->actingAs($this->adminUser)
                ->postJson("/igsns/import/{$importId}/cancel");

            $response->assertStatus(400);
        });

        it('returns 404 for non-existent import', function () {
            $response = $this->actingAs($this->adminUser)
                ->postJson('/igsns/import/a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c62/cancel');

            $response->assertNotFound();
        });
    });
});

describe('IgsnController canImport prop', function () {
    it('returns true for admin users', function () {
        $response = $this->actingAs($this->adminUser)
            ->get('/igsns');

        $response->assertInertia(fn ($page) => $page
            ->has('canImport')
            ->where('canImport', true)
        );
    });

    it('returns true for group leader users', function () {
        $response = $this->actingAs($this->groupLeader)
            ->get('/igsns');

        $response->assertInertia(fn ($page) => $page
            ->where('canImport', true)
        );
    });

    it('returns false for curator users', function () {
        $response = $this->actingAs($this->curator)
            ->get('/igsns');

        $response->assertInertia(fn ($page) => $page
            ->where('canImport', false)
        );
    });

    it('returns false for beginner users', function () {
        $response = $this->actingAs($this->beginner)
            ->get('/igsns');

        $response->assertInertia(fn ($page) => $page
            ->where('canImport', false)
        );
    });
});
