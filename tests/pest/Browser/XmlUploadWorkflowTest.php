<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for XML Upload Workflow
 *
 * Migrated from: tests/playwright/workflows/03-xml-upload-workflow.spec.ts (2 tests)
 *
 * Tests the XML file upload and editor redirect workflow.
 * The original Playwright tests verify upload via the dashboard dropzone
 * and redirect to the editor with session parameters.
 *
 * Note: File upload via browser requires Vite dev server. These tests
 * verify the pages load correctly and the API endpoints work.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('xml-upload', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::CURATOR,
    ]);
    $this->actingAs($user);
});

describe('XML Upload Pages', function (): void {

    it('loads dashboard with dropzone without errors', function (): void {
        visit('/dashboard')
            ->assertNoSmoke();
    });

    it('loads editor page without errors', function (): void {
        visit('/editor')
            ->assertNoSmoke()
            ->assertSee('DOI');
    });
});

describe('XML Upload API', function (): void {

    it('validates XML upload endpoint exists', function (): void {
        /** @var TestCase $this */
        $response = $this->postJson('/api/xml/upload', []);

        // Should return 422 (validation error) not 404
        $response->assertStatus(422);
    });

    it('rejects invalid XML content', function (): void {
        /** @var TestCase $this */
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent(
            'invalid.xml',
            '<invalid>Not a proper DataCite XML</invalid>'
        );

        $response = $this->postJson('/api/xml/upload', [
            'file' => $file,
        ]);

        // Should reject with validation error
        $response->assertStatus(422);
    });

    it('processes valid DataCite XML file', function (): void {
        /** @var TestCase $this */
        $xmlPath = base_path('tests/pest/dataset-examples/datacite-xml-example-full-v4.xml');

        if (! file_exists($xmlPath)) {
            $this->markTestSkipped('DataCite XML example file not found');
        }

        $file = new \Illuminate\Http\UploadedFile(
            $xmlPath,
            'datacite-xml-example-full-v4.xml',
            'application/xml',
            null,
            true
        );

        $response = $this->postJson('/api/xml/upload', [
            'file' => $file,
        ]);

        // Should succeed or redirect
        expect($response->status())->toBeIn([200, 302]);
    });
});
