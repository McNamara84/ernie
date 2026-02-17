<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Smoke Tests for Spatial and Temporal Coverage
 *
 * Converted from 21 original interaction tests. Original tests covered:
 * coordinate input (point, bounding box, polygon), temporal input (dates, times, timezone),
 * description field, entry management (add, collapse, remove), and map picker.
 *
 * These smoke tests verify that the editor loads without JavaScript errors.
 * Full UI interaction testing requires Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('spatial-temporal', 'browser');

describe('Spatial and Temporal Coverage Editor (Smoke)', function (): void {

    it('loads new editor page for coverage workflow without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('loads edit page with existing resource for coverage workflow without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create(['doi' => '10.5880/coverage.smoke.001']);
        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });
});
