<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for IGSN Workflow
 *
 * Converted from 9 original tests. Smoke tests verify dashboard and IGSN pages
 * load without JS errors. HTTP tests verify IGSN upload and export API endpoints.
 *
 * Full file upload E2E workflow requires Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('igsn-workflow', 'browser');

describe('IGSN Page Loading (Smoke)', function (): void {

    it('loads dashboard with unified dropzone without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        visit('/dashboard')
            ->assertNoSmoke();
    });

    it('loads IGSNs page without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        visit('/igsns')
            ->assertNoSmoke();
    });

    it('loads IGSN map page without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        visit('/igsns-map')
            ->assertNoSmoke();
    });
});

describe('IGSN CSV Upload API', function (): void {

    it('rejects upload without a file', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($user)
            ->postJson('/dashboard/upload-igsn-csv', []);

        $response->assertStatus(422);
    });

    it('rejects upload with invalid file type', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

        $response = $this->actingAs($user)
            ->postJson('/dashboard/upload-igsn-csv', [
                'file' => $file,
            ]);

        $response->assertStatus(422);
    });
});

describe('IGSN Export API', function (): void {

    it('returns 404 for non-existent IGSN export', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        $response = $this->actingAs($user)
            ->getJson('/igsns/99999/export/json');

        $response->assertStatus(404);
    });
});
