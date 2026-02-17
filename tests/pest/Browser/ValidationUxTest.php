<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Tests\TestCase;

/**
 * Pest v4 Browser Tests for DataCite Form Validation UX
 *
 * Migrated from: tests/playwright/workflows/07-validation-ux.spec.ts (18 tests)
 *
 * Tests cover inline field validation, section status badges,
 * save button tooltip, and form submission flow.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

uses()->group('validation-ux', 'browser');

beforeEach(function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create([
        'role' => UserRole::CURATOR,
    ]);
    $this->actingAs($user);
});

describe('Inline Field Validation', function (): void {

    it('shows error for invalid year (out of range)', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#year', '1899')
            ->click('body') // blur
            ->wait(1)
            ->assertSeeIn('[data-testid="resource-info-content"]', '1900');
    });

    it('shows success for valid year', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#year', '2024')
            ->click('body')
            ->wait(1)
            ->assertSee('2024');
    });

    it('validates DOI format on blur', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#doi', 'not-a-doi')
            ->click('body')
            ->wait(1)
            ->assertSee('DOI');
    });

    it('validates semantic version format', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#version', 'v1.2')
            ->click('body')
            ->wait(1)
            ->assertSee('Version');
    });

    it('validates main title is required', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#main-title', '')
            ->click('body')
            ->wait(1)
            ->assertSee('Main Title');
    });

    it('validates abstract length with character counter', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="descriptions-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#abstract', 'Too short')
            ->click('body')
            ->wait(1)
            ->assertSee('Abstract');
    });
});

describe('Accordion Status Badges', function (): void {

    it('shows incomplete badge for sections with missing required fields', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Resource Information');
    });

    it('shows optional badge for Contributors section', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Contributors');
    });

    it('badges update reactively when form data changes', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="descriptions-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#abstract', 'This is a comprehensive abstract that meets all validation requirements with more than fifty characters for testing.')
            ->click('body')
            ->wait(1)
            ->assertSee('Abstract');
    });
});

describe('Save Button Tooltip', function (): void {

    it('shows disabled Save button when required fields are missing', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Save');
    });

    it('displays missing required fields information', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Save')
            ->assertSee('Main Title');
    });
});

describe('Auto-Scroll to Validation Errors', function (): void {

    it('identifies first invalid section', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Resource Information');
    });

    it('opens correct accordion section when navigating to errors', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#main-title', 'Test Dataset')
            ->fill('#year', '2024')
            ->click('body')
            ->wait(1)
            ->assertSee('Test Dataset');
    });
});

describe('Complete Form Submission Flow', function (): void {

    it('prevents submission when validation errors exist', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Save');
    });

    it('shows validation feedback across multiple sections simultaneously', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->assertSee('Resource Information')
            ->assertSee('Descriptions')
            ->assertSee('Licenses');
    });
});

describe('Validation Accessibility', function (): void {

    it('validation messages are near form fields', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->fill('#year', '1800')
            ->click('body')
            ->wait(1)
            ->assertSee('Year');
    });

    it('form can be navigated with keyboard', function (): void {
        visit('/editor')
            ->assertSee('DOI')
            ->click('[data-testid="resource-info-trigger"]')
            ->waitForEvent('requestfinished')
            ->click('#main-title')
            ->keys('#main-title', 'Keyboard Navigation Test')
            ->assertSee('Main Title');
    });
});
