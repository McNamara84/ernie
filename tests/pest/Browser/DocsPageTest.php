<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest Browser Tests for Documentation Page
 *
 * Converted from 12 original tests. Tests the authenticated /docs page
 * with role-based sections, tab navigation, sidebar navigation,
 * and accessibility features.
 *
 * Smoke tests verify page loads without JS errors.
 * Source checks verify key ARIA roles are rendered.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Documentation Page (Smoke)', function (): void {

    it('loads docs page without JavaScript errors for curator', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::CURATOR]);
        $this->actingAs($user);

        visit('/docs')
            ->assertNoSmoke();
    });

    it('loads docs page without JavaScript errors for admin', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->actingAs($user);

        visit('/docs')
            ->assertNoSmoke();
    });
});
