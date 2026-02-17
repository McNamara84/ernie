<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for DOI Registration Workflow
 *
 * Converted from 10 original tests. Smoke tests verify resource pages load
 * with different datacite states. HTTP tests verify DOI validation API.
 *
 * Full UI interaction testing (modal open/close, button clicks) requires
 * Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('doi-registration', 'browser');

describe('DOI Registration Pages (Smoke)', function (): void {

    it('loads resources page with published resource without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.published.001',
            'datacite_state' => 'published',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
        ]);

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });

    it('loads resources page with draft resource without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        Resource::factory()->create([
            'doi' => '10.5880/test.badge.draft.001',
            'datacite_state' => 'draft',
        ]);

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });

    it('loads resources list with multiple resources without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);

        Resource::factory()->count(3)->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });
});

describe('DOI Validation API', function (): void {

    it('returns available for new DOI via API', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/available.doi.reg.001',
            ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => false,
            ]);
    });

    it('returns conflict for existing DOI via API', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);

        Resource::factory()->create([
            'doi' => '10.5880/existing.doi.reg.001',
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/doi/validate', [
                'doi' => '10.5880/existing.doi.reg.001',
            ]);

        $response->assertOk()
            ->assertJson([
                'is_valid_format' => true,
                'exists' => true,
            ]);
    });
});
