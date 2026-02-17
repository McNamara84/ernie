<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for XML Upload Workflow
 *
 * Converted from 5 original tests. Smoke tests verify dashboard and editor pages
 * load without JS errors. HTTP tests verify XML upload API endpoint validation.
 *
 * Full file upload E2E workflow (drag-and-drop, editor redirect) requires
 * Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('xml-upload', 'browser');

describe('XML Upload Pages (Smoke)', function (): void {

    it('loads dashboard with dropzone without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/dashboard')
            ->assertNoSmoke();
    });

    it('loads editor page without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });
});

describe('XML Upload API', function (): void {

    it('rejects upload without a file', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $response = $this->actingAs($user)
            ->postJson('/dashboard/upload-xml', []);

        $response->assertStatus(422);
    });

    it('rejects upload with non-XML file type', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $file = UploadedFile::fake()->create('test.txt', 100, 'text/plain');

        $response = $this->actingAs($user)
            ->postJson('/dashboard/upload-xml', [
                'file' => $file,
            ]);

        $response->assertStatus(422);
    });

    it('processes valid DataCite XML file', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $xmlPath = base_path('tests/pest/dataset-examples/datacite-xml-example-full-v4.xml');

        if (! file_exists($xmlPath)) {
            $this->markTestSkipped('DataCite XML example file not found');
        }

        $file = new UploadedFile(
            $xmlPath,
            'datacite-xml-example-full-v4.xml',
            'application/xml',
            null,
            true
        );

        $response = $this->actingAs($user)
            ->postJson('/dashboard/upload-xml', [
                'file' => $file,
            ]);

        // Should succeed or redirect
        expect($response->status())->toBeIn([200, 302]);
    });
});
