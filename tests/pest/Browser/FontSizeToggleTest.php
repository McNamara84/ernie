<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(RefreshDatabase::class);

/**
 * Pest v4 Browser Tests for Font Size Toggle
 *
 * These tests verify:
 * 1. Pages with font size toggle load without JavaScript errors (smoke tests)
 * 2. User font size preference is correctly stored and validated via API
 *
 * Note: These are smoke tests that verify pages load and API works correctly.
 * Full UI interaction tests (clicking the toggle button and verifying CSS class
 * changes in rendered HTML) are in the Playwright E2E suite:
 * - tests/playwright/critical/font-size-toggle.spec.ts
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Font Size Toggle', function (): void {

    it('loads dashboard without JavaScript errors when user preference is large', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
            'font_size_preference' => 'large',
        ]);
        $this->actingAs($user);

        visit('/dashboard')
            ->assertNoSmoke();

        // Verify the user's preference was stored correctly
        $user->refresh();
        expect($user->font_size_preference)->toBe('large');
    });

    it('loads dashboard without JavaScript errors when user preference is regular', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
            'font_size_preference' => 'regular',
        ]);
        $this->actingAs($user);

        visit('/dashboard')
            ->assertNoSmoke();

        // Verify the user's preference was stored correctly
        $user->refresh();
        expect($user->font_size_preference)->toBe('regular');
    });

    it('loads settings appearance page without JavaScript errors', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
        ]);
        $this->actingAs($user);

        visit('/settings/appearance')
            ->assertNoSmoke();
    });

    it('persists font size preference to database via API', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
            'font_size_preference' => 'regular',
        ]);

        $response = $this->actingAs($user)
            ->put('/settings/font-size', [
                'font_size_preference' => 'large',
            ]);

        $response->assertRedirect();

        $user->refresh();
        expect($user->font_size_preference)->toBe('large');
    });

    it('validates font size preference value', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'role' => UserRole::CURATOR,
            'font_size_preference' => 'regular',
        ]);

        $response = $this->actingAs($user)
            ->put('/settings/font-size', [
                'font_size_preference' => 'invalid-value',
            ]);

        $response->assertSessionHasErrors('font_size_preference');
    });
});
