<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest v4 Browser Tests for DOI Validation
 *
 * These tests verify that the DOI validation pages load without errors.
 * Full UI interaction tests require the Vite dev server to be running.
 *
 * For comprehensive E2E testing with UI interactions, use:
 * - npm run dev (start Vite)
 * - npm run test:e2e (Playwright tests)
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('DOI Validation Editor Pages', function (): void {

    it('loads editor page without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('loads edit resource page without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/smoke.test.001',
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });
});

describe('DOI Validation API Endpoints', function (): void {

    it('returns valid response for available DOI', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/available.doi.001',
            ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    it('returns conflict data for existing DOI', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        // Create an existing resource with DOI
        Resource::factory()->create([
            'doi' => '10.5880/existing.doi.001',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/existing.doi.001',
            ]);

        $response->assertOk()
            ->assertJsonStructure([
                'is_valid_format',
                'exists',
                'existing_resource',
                'last_assigned_doi',
                'suggested_doi',
            ])
            ->assertJson([
                'is_valid_format' => true,
                'exists' => true,
            ]);
    });

    it('returns suggested DOI based on pattern', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        // Create sequential DOIs - the service returns lowercase suggestions
        Resource::factory()->create(['doi' => '10.5880/GFZ.1.2026.001']);
        Resource::factory()->create(['doi' => '10.5880/GFZ.1.2026.002']);
        Resource::factory()->create(['doi' => '10.5880/GFZ.1.2026.003']);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/GFZ.1.2026.001',
            ]);

        $response->assertOk()
            ->assertJson([
                'exists' => true,
            ])
            ->assertJsonStructure([
                'suggested_doi',
                'last_assigned_doi',
            ]);

        // Verify suggested DOI is returned (incremented from the searched DOI pattern)
        $data = $response->json();
        expect($data['suggested_doi'])->toBeString();
    });

    it('excludes current resource from conflict check', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/own.resource.001',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/own.resource.001',
                'exclude_resource_id' => $resource->id,
            ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    it('returns error for invalid DOI format', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => 'not-a-valid-doi',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'is_valid_format' => false,
                'exists' => false,
                'error' => 'Invalid DOI format. Expected format: 10.XXXX/suffix',
            ]);
    });
});
