<?php

declare(strict_types=1);

/**
 * Pest Browser Tests for Portal Page
 *
 * Converted from 12 original tests. The public portal page at /portal provides
 * search, filtering, and map-based exploration of published datasets.
 *
 * Smoke tests verify page loads without JS errors.
 * Source checks verify key HTML structure (search input, headings, labels).
 *
 * @see https://pestphp.com/docs/browser-testing
 */

describe('Portal Page (Smoke)', function (): void {

    it('loads portal page without JavaScript errors', function (): void {
        visit('/portal')
            ->assertNoSmoke();
    });

    it('renders the search input', function (): void {
        visit('/portal')
            ->assertSourceHas('type="search"');
    });

    it('has heading structure for accessibility', function (): void {
        visit('/portal')
            ->assertSourceHas('</h1>');
    });

    it('has search input with label for accessibility', function (): void {
        $page = visit('/portal');

        $content = $page->content();
        expect(
            str_contains($content, 'aria-label') || str_contains($content, '<label')
        )->toBeTrue();
    });
});
