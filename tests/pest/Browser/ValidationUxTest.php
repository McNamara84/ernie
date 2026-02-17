<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Smoke Tests for DataCite Form Validation UX
 *
 * Converted from 18 original interaction tests. Original tests covered:
 * inline field validation, section status badges, save button tooltip,
 * auto-scroll to validation errors, and form submission flow.
 *
 * These smoke tests verify that the editor loads without JavaScript errors.
 * Full UI interaction testing requires Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('validation-ux', 'browser');

describe('Validation UX Editor (Smoke)', function (): void {

    it('loads new editor page for validation workflow without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('loads edit page with existing resource for validation workflow without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create(['doi' => '10.5880/validation.smoke.001']);
        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });
});
