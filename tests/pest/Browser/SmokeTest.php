<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Smoke Tests
 *
 * Uses assertNoSmoke() for fast validation of all critical routes.
 * Checks for: JavaScript errors, Console logs
 *
 * Note: These tests use Pest's built-in PHP server. The React/Inertia frontend
 * requires Vite to be running for full rendering. These smoke tests verify:
 * - No JavaScript errors occur
 * - No console errors occur
 * - Routes respond without server errors
 *
 * For full UI testing with rendered content, use Playwright tests instead.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Public Pages Smoke Test', function (): void {
    it('loads public pages without JavaScript errors', function (): void {
        $pages = visit([
            '/login',
            '/about',
            '/legal-notice',
            '/changelog',
        ]);

        $pages->assertNoSmoke();
    });
});

describe('Authenticated Pages Smoke Test', function (): void {
    it('loads authenticated pages without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        $pages = visit([
            '/dashboard',
            '/editor',
            '/resources',
            '/settings',
            '/settings/appearance',
        ]);

        $pages->assertNoSmoke();
    });
});

describe('Admin Pages Smoke Test', function (): void {
    it('loads admin pages without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $admin = User::factory()->create([
            'role' => UserRole::ADMIN,
        ]);
        $this->actingAs($admin);

        $pages = visit([
            '/users',
            '/logs',
            '/settings',
            '/settings/appearance',
        ]);

        $pages->assertNoSmoke();
    });
});
