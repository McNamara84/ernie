<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Vite;
use Tests\TestCase;

uses()->group('issue-499', 'browser', 'navigation');

describe('Issue 499 mobile navigation header', function (): void {
    beforeEach(function (): void {
        app(Vite::class)
            ->useHotFile(storage_path('framework/testing-vite.hot'))
            ->useBuildDirectory('build');
    });

    it('keeps the complete mobile header available while scrolling and preserves desktop behavior', function (): void {
        /** @var TestCase $this */
        $user = User::factory()->create([
            'email' => 'issue-499-curator@example.test',
            'name' => 'Issue 499 Curator',
            'role' => UserRole::CURATOR,
        ]);

        $this->actingAs($user);

        $page = visit('/docs')
            ->resize(375, 667)
            ->waitForText('Documentation')
            ->assertNoSmoke()
            ->assertVisible('[data-slot="app-sidebar-header"]')
            ->assertVisible('[data-slot="sidebar-trigger"]');

        $initialState = $page->script(<<<'JS'
            () => {
                const header = document.querySelector('[data-slot="app-sidebar-header"]');
                const content = document.querySelector('[data-slot="sidebar-inset"]');

                if (!(header instanceof HTMLElement) || !(content instanceof HTMLElement)) {
                    return null;
                }

                const contentStyle = getComputedStyle(content);

                return {
                    documentHeight: document.documentElement.scrollHeight,
                    viewportHeight: window.innerHeight,
                    headerPosition: getComputedStyle(header).position,
                    contentOverflowX: contentStyle.overflowX,
                    contentOverflowY: contentStyle.overflowY,
                };
            }
            JS);

        expect($initialState)->not->toBeNull();
        expect($initialState['documentHeight'])->toBeGreaterThan($initialState['viewportHeight']);
        expect($initialState['headerPosition'])->toBe('sticky');
        expect($initialState['contentOverflowX'])->toBe('clip');
        expect($initialState['contentOverflowY'])->toBe('visible');

        $page->script(<<<'JS'
            () => new Promise((resolve) => {
                const maximumScroll = document.documentElement.scrollHeight - window.innerHeight;
                window.scrollTo(0, Math.min(1200, maximumScroll));

                requestAnimationFrame(() => requestAnimationFrame(resolve));
            })
            JS);

        $scrolledState = $page->script(<<<'JS'
            () => {
                const header = document.querySelector('[data-slot="app-sidebar-header"]');
                const trigger = document.querySelector('[data-slot="sidebar-trigger"]');

                if (!(header instanceof HTMLElement) || !(trigger instanceof HTMLElement)) {
                    return null;
                }

                const headerRect = header.getBoundingClientRect();
                const triggerRect = trigger.getBoundingClientRect();
                const headerStyle = getComputedStyle(header);
                const triggerStyle = getComputedStyle(trigger);

                return {
                    scrollY: window.scrollY,
                    headerTop: headerRect.top,
                    headerBottom: headerRect.bottom,
                    headerPosition: headerStyle.position,
                    headerBackgroundColor: headerStyle.backgroundColor,
                    triggerVisible:
                        triggerStyle.display !== 'none'
                        && triggerStyle.visibility !== 'hidden'
                        && triggerRect.top >= 0
                        && triggerRect.bottom <= window.innerHeight,
                };
            }
            JS);

        expect($scrolledState)->not->toBeNull();
        expect($scrolledState['scrollY'])->toBeGreaterThan(0);
        expect($scrolledState['headerPosition'])->toBe('sticky');
        expect(abs((float) $scrolledState['headerTop']))->toBeLessThanOrEqual(1.0);
        expect($scrolledState['headerBottom'])->toBeGreaterThan(50);
        expect($scrolledState['headerBackgroundColor'])->not->toBe('rgba(0, 0, 0, 0)');
        expect($scrolledState['headerBackgroundColor'])->not->toBe('transparent');
        expect($scrolledState['triggerVisible'])->toBeTrue();

        $page->click('[data-slot="sidebar-trigger"]')
            ->assertVisible('[data-mobile="true"]')
            ->assertVisible('[data-slot="sheet-overlay"]');

        $openNavigationState = $page->script(<<<'JS'
            () => {
                const header = document.querySelector('[data-slot="app-sidebar-header"]');
                const mobileSidebar = document.querySelector('[data-mobile="true"]');
                const overlay = document.querySelector('[data-slot="sheet-overlay"]');

                if (
                    !(header instanceof HTMLElement)
                    || !(mobileSidebar instanceof HTMLElement)
                    || !(overlay instanceof HTMLElement)
                ) {
                    return null;
                }

                return {
                    headerZIndex: Number.parseInt(getComputedStyle(header).zIndex, 10),
                    sidebarZIndex: Number.parseInt(getComputedStyle(mobileSidebar).zIndex, 10),
                    overlayZIndex: Number.parseInt(getComputedStyle(overlay).zIndex, 10),
                };
            }
            JS);

        expect($openNavigationState)->not->toBeNull();
        expect($openNavigationState['sidebarZIndex'])->toBeGreaterThan($openNavigationState['headerZIndex']);
        expect($openNavigationState['overlayZIndex'])->toBeGreaterThan($openNavigationState['headerZIndex']);

        $page->resize(768, 667)->wait(0.2);

        $desktopState = $page->script(<<<'JS'
            () => {
                const header = document.querySelector('[data-slot="app-sidebar-header"]');
                const desktopSidebar = document.querySelector('[data-slot="sidebar-container"]');

                if (!(header instanceof HTMLElement) || !(desktopSidebar instanceof HTMLElement)) {
                    return null;
                }

                const desktopSidebarStyle = getComputedStyle(desktopSidebar);
                const desktopSidebarRect = desktopSidebar.getBoundingClientRect();

                return {
                    headerPosition: getComputedStyle(header).position,
                    mobileSidebarPresent: document.querySelector('[data-mobile="true"]') !== null,
                    desktopSidebarVisible:
                        desktopSidebarStyle.display !== 'none'
                        && desktopSidebarRect.width > 0
                        && desktopSidebarRect.height > 0,
                };
            }
            JS);

        expect($desktopState)->not->toBeNull();
        expect($desktopState['headerPosition'])->toBe('static');
        expect($desktopState['mobileSidebarPresent'])->toBeFalse();
        expect($desktopState['desktopSidebarVisible'])->toBeTrue();
        $page->assertNoSmoke();
    });
});
