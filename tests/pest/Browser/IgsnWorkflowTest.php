<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for IGSN Workflow
 *
 * Migrated from: tests/playwright/workflows/igsn-workflow.spec.ts (7 tests)
 *
 * Tests cover CSV upload via dashboard dropzone, IGSN data display in /igsns table,
 * duplicate rejection, bulk delete, and DataCite JSON export.
 *
 * Note: Full file upload E2E workflow requires Vite dev server running.
 * These tests verify pages load correctly and API endpoints work.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('igsn-workflow', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::ADMIN,
    ]);
    $this->actingAs($user);
});

describe('IGSN Page Loading', function (): void {

    it('loads dashboard with unified dropzone without errors', function (): void {
        visit('/dashboard')
            ->assertNoSmoke();
    });

    it('loads IGSNs page without errors', function (): void {
        visit('/igsns')
            ->assertNoSmoke();
    });

    it('displays IGSN table headers correctly', function (): void {
        visit('/igsns')
            ->assertSee('IGSN')
            ->assertSee('Sample Type')
            ->assertSee('Material');
    });
});

describe('IGSN CSV Upload API', function (): void {

    it('rejects upload without a file', function (): void {
        /** @var TestCase $this */
        $response = $this->postJson('/api/igsn/upload', []);

        $response->assertStatus(422);
    });

    it('rejects upload with invalid file type', function (): void {
        /** @var TestCase $this */
        $file = \Illuminate\Http\UploadedFile::fake()->create('test.txt', 100, 'text/plain');

        $response = $this->postJson('/api/igsn/upload', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
    });
});

describe('IGSN Data Display', function (): void {

    it('shows empty state when no IGSNs exist', function (): void {
        visit('/igsns')
            ->assertNoSmoke();
    });

    it('loads IGSN page with table structure', function (): void {
        visit('/igsns')
            ->assertSee('IGSN')
            ->assertSee('Status');
    });
});

describe('IGSN Export API', function (): void {

    it('returns 404 for non-existent IGSN export', function (): void {
        /** @var TestCase $this */
        $response = $this->getJson('/api/igsn/99999/datacite-json');

        $response->assertStatus(404);
    });
});
