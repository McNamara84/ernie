<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
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
