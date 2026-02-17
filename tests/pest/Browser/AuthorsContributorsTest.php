<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Resource;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Smoke Tests for Authors and Contributors Form
 *
 * Converted from interaction tests (17 original tests).
 * Original tests covered: adding/removing authors and contributors,
 * type switching (person/institution), ORCID validation,
 * CSV import dialogs, drag & drop handles, ORCID search dialog,
 * and accessibility.
 *
 * These smoke tests verify that the editor loads without JavaScript errors.
 * Full UI interaction testing requires Playwright E2E tests with Vite dev server.
 *
 * @see tests/playwright/authors-contributors.spec.ts
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('authors-contributors', 'browser');

describe('Authors and Contributors Editor (Smoke)', function (): void {

    it('loads new editor page without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/editor')
            ->assertNoSmoke();
    });

    it('loads edit page with existing resource without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $resource = Resource::factory()->create(['doi' => '10.5880/authors.smoke.001']);
        $this->actingAs($user);

        visit("/resources/{$resource->id}/edit")
            ->assertNoSmoke();
    });
});
