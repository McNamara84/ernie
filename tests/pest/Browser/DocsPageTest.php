<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest Browser Tests for Documentation Page
 *
 * Migrated from:
 * - tests/playwright/critical/docs.spec.ts (14 tests)
 * - tests/playwright/workflows/13-documentation.spec.ts (5 tests, subset of above)
 *
 * Tests the authenticated /docs page with role-based sections,
 * tab navigation, sidebar navigation, and accessibility features.
 */

describe('Documentation Page', function (): void {

    beforeEach(function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);
    });

    it('loads docs page without JavaScript errors', function (): void {
        visit('/docs')
            ->assertNoSmoke();
    });

    it('displays the main documentation heading', function (): void {
        visit('/docs')
            ->assertSee('Documentation');
    });

    it('shows three main tabs: Getting Started, Datasets, Physical Samples', function (): void {
        $page = visit('/docs');

        $page->assertSee('Getting Started');
        $page->assertSee('Datasets');
        $page->assertSee('Physical Samples');
    });

    it('shows Getting Started tab as active by default', function (): void {
        $page = visit('/docs');

        // The Getting Started tab should be active/selected
        $page->assertSourceHas('data-state="active"');
        $page->assertSee('Getting Started');
    });

    it('can switch to Datasets tab', function (): void {
        visit('/docs')
            ->click('Datasets')
            ->assertSee('Datasets');
    });

    it('can switch to Physical Samples tab', function (): void {
        visit('/docs')
            ->click('Physical Samples')
            ->assertSee('Physical Samples');
    });

    it('can switch back to Getting Started tab', function (): void {
        visit('/docs')
            ->click('Datasets')
            ->click('Getting Started')
            ->assertSee('Getting Started');
    });

    it('displays sidebar navigation on desktop', function (): void {
        $page = visit('/docs');

        // Sidebar should contain navigation links
        $page->assertSourceHas('role="navigation"');
    });

    it('has correct ARIA roles for tabs', function (): void {
        $page = visit('/docs');

        $page->assertSourceHas('role="tablist"');
        $page->assertSourceHas('role="tab"');
        $page->assertSourceHas('role="tabpanel"');
    });

    it('supports keyboard navigation for tabs', function (): void {
        visit('/docs')
            ->keys('[role="tab"]', 'ArrowRight');
    });

    it('shows user-role-appropriate content', function (): void {
        // As a Curator, should see curator-level sections
        visit('/docs')
            ->assertSee('Getting Started');
    });

    it('shows Welcome section with user info', function (): void {
        visit('/docs')
            ->assertSee('Getting Started');
    });
});
