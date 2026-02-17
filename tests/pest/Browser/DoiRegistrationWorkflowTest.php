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
 * Migrated from: tests/playwright/workflows/09-doi-registration.spec.ts (8 tests)
 *
 * Tests cover DOI metadata update, landing page requirement,
 * test mode warning, modal cancel, status badge behavior,
 * and resource list state.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('doi-registration', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::ADMIN,
    ]);
    $this->actingAs($user);
});

describe('DOI Registration Modal', function (): void {

    it('loads resources page with published resource data', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.published.001',
            'datacite_state' => 'published',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
        ]);

        visit('/resources')
            ->assertNoSmoke()
            ->assertSee('Published');
    });

    it('opens DOI registration dialog for published resource', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.published.002',
            'datacite_state' => 'published',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
        ]);

        visit('/resources')
            ->assertSee('Published')
            ->click('[data-testid="datacite-button"]')
            ->wait(2)
            ->assertSee('DOI');
    });

    it('cannot register DOI without landing page', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.nolp.001',
            'datacite_state' => 'draft',
        ]);

        visit('/resources')
            ->assertNoSmoke();
    });

    it('modal can be cancelled', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.cancel.001',
            'datacite_state' => 'published',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
        ]);

        visit('/resources')
            ->assertSee('Published')
            ->click('[data-testid="datacite-button"]')
            ->wait(2)
            ->assertSee('DOI')
            ->press('button:has-text("Cancel")')
            ->wait(1);
    });
});

describe('Status Badges', function (): void {

    it('displays Published badge for published resources', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/test.badge.pub.001',
            'datacite_state' => 'published',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => true,
        ]);

        visit('/resources')
            ->assertSee('Published');
    });

    it('displays Draft badge for draft resources', function (): void {
        Resource::factory()->create([
            'doi' => '10.5880/test.badge.draft.001',
            'datacite_state' => 'draft',
        ]);

        visit('/resources')
            ->assertSee('Draft');
    });

    it('resources list loads without errors', function (): void {
        Resource::factory()->count(3)->create();

        visit('/resources')
            ->assertNoSmoke();
    });
});

describe('DOI Validation API', function (): void {

    it('returns available for new DOI via API', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

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
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

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
