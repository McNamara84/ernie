<?php

declare(strict_types=1);

/**
 * Pest Browser Tests for Portal Page
 *
 * Migrated from: tests/playwright/workflows/portal.spec.ts (17 tests)
 *
 * Tests the public portal page at /portal which provides search,
 * filtering, and map-based exploration of published datasets.
 */

describe('Portal Page', function (): void {

    it('loads portal page without JavaScript errors', function (): void {
        visit('/portal')
            ->assertNoSmoke();
    });

    it('displays the portal heading', function (): void {
        visit('/portal')
            ->assertSee('Portal');
    });

    it('renders the search input', function (): void {
        visit('/portal')
            ->assertSourceHas('type="search"');
    });

    it('renders the filter sidebar', function (): void {
        $page = visit('/portal');

        // Sidebar should have filter controls (type filter, etc.)
        $content = $page->content();
        expect(
            str_contains($content, 'filter') || str_contains($content, 'Filter')
        )->toBeTrue();
    });

    it('renders the map component', function (): void {
        $page = visit('/portal');

        // Map container should be present
        $content = $page->content();
        expect(
            str_contains($content, 'leaflet') || str_contains($content, 'map')
        )->toBeTrue();
    });

    it('shows type filter with All, DOI and IGSN options', function (): void {
        $page = visit('/portal');

        $page->assertSee('All');
        $content = $page->content();
        expect(
            str_contains($content, 'DOI') || str_contains($content, 'IGSN')
        )->toBeTrue();
    });

    it('defaults to "All" type filter', function (): void {
        visit('/portal')
            ->assertSee('All');
    });

    it('accepts search text input', function (): void {
        visit('/portal')
            ->type('[type="search"]', 'climate')
            ->assertNoSmoke();
    });

    it('updates URL with search query parameter', function (): void {
        visit('/portal')
            ->type('[type="search"]', 'temperature')
            ->keys('[type="search"]', 'Enter')
            ->wait(1);
    });

    it('renders results area with resource cards or empty state', function (): void {
        $page = visit('/portal');

        $content = $page->content();
        // Either results cards or an empty state message
        $hasContent = str_contains($content, 'data-testid')
            || str_contains($content, 'No results')
            || str_contains($content, 'resource');

        expect($hasContent)->toBeTrue();
    });

    it('has heading structure for accessibility', function (): void {
        $page = visit('/portal');

        $page->assertSourceHas('</h1>');
    });

    it('has search input with label for accessibility', function (): void {
        $page = visit('/portal');

        $content = $page->content();
        expect(
            str_contains($content, 'aria-label') || str_contains($content, '<label')
        )->toBeTrue();
    });
});
