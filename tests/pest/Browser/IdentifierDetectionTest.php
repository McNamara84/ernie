<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Smoke Tests for Related Work Identifier Type Auto-Detection
 *
 * Converted from 17 original interaction tests. Original tests verified that
 * the Related Work form correctly auto-detects identifier types when users
 * enter identifiers (DOI, ARK, arXiv, Handle, URL, ISBN, PMID, etc.).
 *
 * Comprehensive pattern testing is handled by Vitest unit tests in:
 * - tests/vitest/__tests__/identifier-type-detection.test.ts
 *
 * These smoke tests verify editor pages load without JS errors.
 * Full UI interaction testing requires Playwright E2E tests with Vite dev server.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('identifier-detection', 'browser');

describe('Identifier Detection Editor (Smoke)', function (): void {

    it('loads new editor page for identifier detection without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('loads edit page with existing resource for identifier detection without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create(['doi' => '10.5880/identifier.smoke.001']);
        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });
});
