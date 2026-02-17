<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\LandingPage;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for Landing Page Preview Workflow
 *
 * Migrated from: tests/playwright/workflows/11-landing-page-preview.spec.ts (1 test)
 *
 * Tests the session-based preview functionality for landing pages.
 * The original test opens a new tab via the resources page setup modal.
 * Here we verify the preview endpoint works correctly.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('landing-page-preview', 'browser');

describe('Landing Page Preview', function (): void {

    it('preview endpoint loads for authenticated user', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/preview.test.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
            'slug' => 'test-preview-page',
        ]);

        $this->actingAs($user);

        visit("/resources/{$resource->id}/landing-page/preview")
            ->assertNoSmoke();
    });

    it('preview with token loads for unauthenticated access', function (): void {
        $resource = Resource::factory()->create([
            'doi' => '10.5880/preview.token.001',
        ]);

        $landingPage = LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
            'slug' => 'test-preview-token',
            'preview_token' => 'test-preview-token-abc123',
        ]);

        visit("/draft-{$resource->id}/test-preview-token?preview=test-preview-token-abc123")
            ->assertNoSmoke();
    });

    it('resources page loads with landing page management', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);

        $resource = Resource::factory()->create([
            'doi' => '10.5880/preview.resources.001',
        ]);

        LandingPage::factory()->create([
            'resource_id' => $resource->id,
            'template' => 'default_gfz',
            'is_published' => false,
            'slug' => 'test-preview-resources',
        ]);

        $this->actingAs($user);

        visit('/resources')
            ->assertNoSmoke();
    });
});
