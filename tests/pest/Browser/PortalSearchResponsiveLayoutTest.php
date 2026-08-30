<?php

declare(strict_types=1);

use Illuminate\Foundation\Vite;

uses()->group('browser', 'portal');

describe('Portal search responsive workspace', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');
    });

    it('keeps desktop filters and results inside the viewport', function (int $width, int $height): void {
        $page = visit('/search')
            ->resize($width, $height)
            ->waitForText('Filters')
            ->assertNoSmoke();

        $state = $page->script(<<<'JS'
            () => {
                const workspace = document.querySelector('[data-testid="portal-workspace"]');
                const sidebar = document.querySelector('[data-testid="portal-filter-sidebar"]');
                const search = document.querySelector('#portal-search');
                const wordmark = document.querySelector('[data-testid="portal-wordmark"]');
                const scrollViewport = sidebar?.querySelector('[data-radix-scroll-area-viewport]');

                if (!(workspace instanceof HTMLElement)
                    || !(sidebar instanceof HTMLElement)
                    || !(search instanceof HTMLElement)
                    || !(wordmark instanceof HTMLElement)
                    || !(scrollViewport instanceof HTMLElement)) {
                    return null;
                }

                const workspaceRect = workspace.getBoundingClientRect();
                const sidebarRect = sidebar.getBoundingClientRect();

                return {
                    documentHeight: document.documentElement.scrollHeight,
                    viewportHeight: window.innerHeight,
                    scrollY: window.scrollY,
                    workspaceBottom: workspaceRect.bottom,
                    sidebarBottom: sidebarRect.bottom,
                    sidebarHeight: sidebarRect.height,
                    workspaceHeight: workspaceRect.height,
                    searchInsideFilterScroller: scrollViewport.contains(search),
                    filterOverflowY: getComputedStyle(scrollViewport).overflowY,
                    mapCount: document.querySelectorAll('[data-testid="portal-map-container"]').length,
                    heroPresent: document.body.textContent?.includes('Explore published records') ?? false,
                    footerPresent: document.querySelector('footer') !== null,
                    mobileToolbarPresent: document.querySelector('[data-testid="portal-filter-drawer-trigger"]') !== null,
                    wordmarkVisuallyVisible: wordmark.getBoundingClientRect().width > 1,
                };
            }
            JS);

        expect($state)->not->toBeNull();
        expect($state['documentHeight'])->toBeLessThanOrEqual($state['viewportHeight'] + 1);
        expect($state['scrollY'])->toBe(0);
        expect($state['workspaceBottom'])->toBeLessThanOrEqual($state['viewportHeight'] + 1);
        expect($state['sidebarBottom'])->toBeLessThanOrEqual($state['viewportHeight'] + 1);
        expect(abs($state['sidebarHeight'] - $state['workspaceHeight']))->toBeLessThanOrEqual(1.0);
        expect($state['searchInsideFilterScroller'])->toBeFalse();
        expect($state['filterOverflowY'])->toBeIn(['auto', 'scroll']);
        expect($state['mapCount'])->toBe(1);
        expect($state['heroPresent'])->toBeFalse();
        expect($state['footerPresent'])->toBeFalse();
        expect($state['mobileToolbarPresent'])->toBeFalse();
        expect($state['wordmarkVisuallyVisible'])->toBeTrue();
    })->with([
        'wide desktop' => [1920, 1080],
        'compact desktop' => [1440, 900],
    ]);

    it('uses a filter drawer and one switchable content view below desktop width', function (int $width, int $height, bool $wordmarkVisible): void {
        $page = visit('/search')
            ->resize($width, $height)
            ->waitForText('Results')
            ->assertNoSmoke()
            ->assertVisible('[data-testid="portal-filter-drawer-trigger"]')
            ->assertVisible('[data-testid="portal-mobile-results-view"]');

        $initialState = $page->script(<<<'JS'
            () => ({
                documentHeight: document.documentElement.scrollHeight,
                viewportHeight: window.innerHeight,
                sidebarCount: document.querySelectorAll('[data-testid="portal-filter-sidebar"]').length,
                mapCount: document.querySelectorAll('[data-testid="portal-map-container"]').length,
                resultViewCount: document.querySelectorAll('[data-testid="portal-mobile-results-view"]').length,
                footerPresent: document.querySelector('footer') !== null,
                wordmarkWidth: document.querySelector('[data-testid="portal-wordmark"]')?.getBoundingClientRect().width ?? 0,
            })
            JS);

        expect($initialState['documentHeight'])->toBeLessThanOrEqual($initialState['viewportHeight'] + 1);
        expect($initialState['sidebarCount'])->toBe(0);
        expect($initialState['mapCount'])->toBe(0);
        expect($initialState['resultViewCount'])->toBe(1);
        expect($initialState['footerPresent'])->toBeFalse();
        expect($initialState['wordmarkWidth'] > 1)->toBe($wordmarkVisible);

        $page->click('[data-testid="portal-mobile-map-tab"]')
            ->wait(0.3)
            ->assertVisible('[data-testid="portal-mobile-map-view"]');

        $mapState = $page->script(<<<'JS'
            () => ({
                mapCount: document.querySelectorAll('[data-testid="portal-map-container"]').length,
                resultViewCount: document.querySelectorAll('[data-testid="portal-mobile-results-view"]').length,
                documentHeight: document.documentElement.scrollHeight,
                viewportHeight: window.innerHeight,
            })
            JS);

        expect($mapState['mapCount'])->toBe(1);
        expect($mapState['resultViewCount'])->toBe(0);
        expect($mapState['documentHeight'])->toBeLessThanOrEqual($mapState['viewportHeight'] + 1);

        $page->click('[data-testid="portal-filter-drawer-trigger"]')
            ->wait(0.2)
            ->assertVisible('[data-testid="portal-filter-sidebar"]')
            ->assertNoSmoke();
    })->with([
        'tablet' => [1024, 768, true],
        'mobile' => [393, 852, false],
    ]);
});
