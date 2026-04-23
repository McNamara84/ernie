<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\LandingPageTemplate;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Landing Page Template Management
 *
 * These tests verify the landing page setup modal and template modification workflow.
 * Uses Pest Browser plugin for E2E testing with Playwright under the hood.
 *
 * Note: Browser tests require Playwright to be installed locally.
 * Run `npx playwright install` before running these tests.
 *
 * @see Issue #375 - Enable subsequent modification of the landing page template
 * @see https://pestphp.com/docs/browser-testing
 */
uses()->group('landing-pages', 'browser');

describe('Landing Page Button Visibility (Smoke)', function (): void {
    it('loads resources page without errors for curators', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        Resource::factory()->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });

    it('loads resources page without errors for beginners', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::BEGINNER,
        ]);

        Resource::factory()->create();

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });
});

describe('Landing Page Setup Modal (Smoke)', function (): void {
    it('loads resource show page without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create();

        $this->actingAs($user);

        visit("/resources/{$resource->id}")
            ->assertNoSmoke();
    });

    it('loads resource with existing landing page without errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create();
        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}")
            ->assertNoSmoke();
    });
});

describe('Landing Page Template Persistence (Regression)', function (): void {
    // Regression coverage for the Setup Landing Page dialog losing its custom
    // template selection on reopen. Before the fix, the select fell back to
    // "Default GFZ Data Services" even when the resource had a custom
    // landing_page_template_id persisted, because the loadLandingPageConfig()
    // path did not hydrate the state.

    it('shows the previously assigned custom template in the Setup Landing Page dialog', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $customTemplate = LandingPageTemplate::factory()->create([
            'name' => 'Regression Custom Template',
            'slug' => 'regression-custom-template',
            'is_default' => false,
            'created_by' => $user->id,
        ]);

        $resource = Resource::factory()->create();
        LandingPage::factory()->draft()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'landing_page_template_id' => $customTemplate->id,
        ]);

        $this->actingAs($user);

        $page = visit('/resources')->assertNoSmoke();

        // Open the Setup Landing Page dialog for this resource. The button is
        // rendered with an aria-label containing "Setup landing page for
        // resource" and a DOI/resource identifier.
        $page->click('[aria-label^="Setup landing page for resource"]')
            ->assertSee('Setup Landing Page')
            // The Select trigger must display the custom template name, not
            // the "Default GFZ Data Services" fallback value.
            ->assertSee('Regression Custom Template')
            ->assertDontSee('Default GFZ Data Services');
    });
});
