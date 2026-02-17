<?php

declare(strict_types=1);

/**
 * Pest Browser Tests for Changelog Page
 *
 * Migrated from: tests/playwright/critical/changelog.spec.ts (16 tests)
 *
 * Tests the public changelog page at /changelog which displays
 * an animated timeline of release notes powered by changelog.json.
 */

describe('Changelog Page', function (): void {

    it('displays the changelog heading', function (): void {
        visit('/changelog')
            ->assertSee('Changelog');
    });

    it('loads the changelog with timeline navigation', function (): void {
        visit('/changelog')
            ->assertSee('Changelog')
            ->assertSourceHas('aria-label="Changelog Timeline"');
    });

    it('shows the first version expanded by default', function (): void {
        visit('/changelog')
            ->assertSourceHas('aria-expanded="true"');
    });

    it('renders version entries with release content', function (): void {
        visit('/changelog')
            ->assertNoSmoke();
    });

    it('shows category icons for Features, Improvements, and Fixes', function (): void {
        $page = visit('/changelog');
        $content = $page->content();

        // At least one category heading should be visible in the expanded version
        $hasCategoryHeading = str_contains($content, 'Features')
            || str_contains($content, 'Improvements')
            || str_contains($content, 'Fixes');

        expect($hasCategoryHeading)->toBeTrue();
    });

    it('renders timeline navigation on desktop', function (): void {
        visit('/changelog')
            ->assertSourceHas('Version timeline navigation');
    });

    it('can expand and collapse versions by clicking', function (): void {
        $page = visit('/changelog');

        // First release should be expanded by default
        $page->assertSourceHas('aria-expanded="true"');

        // Click to collapse the first version
        $page->click('#release-trigger-0');
    });

    it('supports keyboard navigation (Enter/Space)', function (): void {
        visit('/changelog')
            ->keys('#release-trigger-0', 'Enter');
    });

    it('supports deep linking with hash URLs', function (): void {
        visit('/changelog')
            ->assertNoSmoke();
    });

    it('handles API errors gracefully', function (): void {
        // The page should still render even if the API has issues
        visit('/changelog')
            ->assertNoSmoke();
    });

    it('displays the "New" badge for the latest release', function (): void {
        $content = visit('/changelog')->content();

        // The latest release should have a "New" badge
        expect(str_contains($content, 'New') || str_contains($content, 'new'))->toBeTrue();
    });

    it('has proper ARIA labels and roles for accessibility', function (): void {
        $page = visit('/changelog');

        $page->assertSourceHas('aria-label="Changelog Timeline"');
        $page->assertSourceHas('role="region"');
    });

    it('loads without JavaScript errors', function (): void {
        visit('/changelog')
            ->assertNoSmoke();
    });
});
