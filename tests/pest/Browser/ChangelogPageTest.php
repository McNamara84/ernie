<?php

declare(strict_types=1);

/**
 * Pest Browser Tests for Changelog Page
 *
 * Converted from 13 original tests. Tests the public changelog page at /changelog
 * which displays an animated timeline of release notes powered by changelog.json.
 *
 * Smoke tests verify page loads without JS errors.
 * Source checks verify key HTML structure is rendered by React.
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Changelog Page (Smoke)', function (): void {

    it('loads the changelog page without JavaScript errors', function (): void {
        visit('/changelog')
            ->assertNoSmoke();
    });

    it('renders timeline navigation structure', function (): void {
        $page = visit('/changelog');

        $page->assertNoSmoke();
        $page->assertSourceHas('aria-label="Changelog Timeline"');
    });

    it('shows the first version expanded by default', function (): void {
        visit('/changelog')
            ->assertSourceHas('aria-expanded="true"');
    });

    it('renders version entries with region role for accessibility', function (): void {
        $page = visit('/changelog');

        $page->assertSourceHas('aria-label="Changelog Timeline"');
        $page->assertSourceHas('role="region"');
    });

    it('renders release content with category headings', function (): void {
        $page = visit('/changelog');
        $content = $page->content();

        // At least one category heading should be visible in the expanded version
        $hasCategoryHeading = str_contains($content, 'Features')
            || str_contains($content, 'Improvements')
            || str_contains($content, 'Fixes');

        expect($hasCategoryHeading)->toBeTrue();
    });
});
