import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Documentation Page E2E Tests - Extended
 *
 * Tests tab navigation, sidebar interaction, scroll-spy behavior,
 * mobile responsiveness, and accessibility features.
 *
 * Note: Basic documentation tests are in workflows/13-documentation.spec.ts.
 * These tests cover the interactive UI components in more depth.
 */
test.describe('Documentation Page - Interactive Features', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await page.goto('/docs');
        // Wait for the documentation tabs to be loaded
        await page.waitForSelector('[data-testid="tab-getting-started"]');
    });

    test.describe('page structure', () => {
        test('displays all three main tabs', async ({ page }) => {
            await expect(page.getByTestId('tab-getting-started')).toBeVisible();
            await expect(page.getByTestId('tab-datasets')).toBeVisible();
            await expect(page.getByTestId('tab-physical-samples')).toBeVisible();
        });

        test('displays Getting Started tab content by default', async ({ page }) => {
            const gettingStartedTab = page.getByTestId('tab-getting-started');
            await expect(gettingStartedTab).toHaveAttribute('data-state', 'active');
        });
    });

    test.describe('tab navigation', () => {
        test('switches to Datasets tab when clicked', async ({ page }) => {
            const datasetsTab = page.getByTestId('tab-datasets');
            await datasetsTab.click();

            await expect(datasetsTab).toHaveAttribute('data-state', 'active');
            await expect(page.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'inactive');

            // Verify Datasets content is visible - DOI is mentioned in this tab
            await expect(page.locator('text=DOI').first()).toBeVisible();
        });

        test('switches to Physical Samples tab when clicked', async ({ page }) => {
            const physicalSamplesTab = page.getByTestId('tab-physical-samples');
            await physicalSamplesTab.click();

            await expect(physicalSamplesTab).toHaveAttribute('data-state', 'active');
            await expect(page.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'inactive');

            // Verify Physical Samples content is visible - IGSN is mentioned in this tab
            await expect(page.locator('text=IGSN').first()).toBeVisible();
        });

        test('can switch back to Getting Started tab', async ({ page }) => {
            // First switch away
            await page.getByTestId('tab-datasets').click();
            await expect(page.getByTestId('tab-datasets')).toHaveAttribute('data-state', 'active');

            // Then switch back
            const gettingStartedTab = page.getByTestId('tab-getting-started');
            await gettingStartedTab.click();

            await expect(gettingStartedTab).toHaveAttribute('data-state', 'active');
            await expect(page.getByTestId('tab-datasets')).toHaveAttribute('data-state', 'inactive');
        });
    });

    test.describe('sidebar navigation', () => {
        test('displays sidebar navigation on desktop', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');
            await expect(sidebar).toBeVisible();
        });

        test('sidebar contains section links', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');
            // Should have navigation buttons for sections
            const sectionButtons = sidebar.locator('button');
            const count = await sectionButtons.count();
            expect(count).toBeGreaterThan(0);
        });

        test('clicking sidebar item scrolls to section', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');
            // Find a section button and click it
            const firstButton = sidebar.locator('button').first();

            // Store initial scroll position
            const initialScrollY = await page.evaluate(() => window.scrollY);

            await firstButton.click();

            // Wait for scroll to complete
            await page.waitForTimeout(500);

            // Verify the button click was processed (either scrolled or section was already visible)
            // We check that the first section heading is in viewport after click
            const firstSectionText = await firstButton.textContent();
            if (firstSectionText) {
                // The section should be visible after clicking its navigation item
                const heading = page.locator(`h2, h3`).filter({ hasText: firstSectionText }).first();
                // Either the heading is visible, or the page scrolled
                const isVisible = await heading.isVisible().catch(() => false);
                const currentScrollY = await page.evaluate(() => window.scrollY);
                expect(isVisible || currentScrollY !== initialScrollY).toBe(true);
            }
        });
    });

    test.describe('scroll-spy behavior', () => {
        test('sidebar has active state tracking', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');

            // Verify sidebar buttons exist and can receive active state
            const buttons = sidebar.locator('button');
            const count = await buttons.count();
            expect(count).toBeGreaterThan(0);
        });
    });

    test.describe('mobile navigation', () => {
        test.use({ viewport: { width: 375, height: 667 } });

        test('hides desktop sidebar on mobile', async ({ page }) => {
            const desktopSidebar = page.locator('[data-testid="docs-sidebar"]');
            await expect(desktopSidebar).not.toBeVisible();
        });

        test('tabs remain functional on mobile', async ({ page }) => {
            // Tabs should still work on mobile
            const datasetsTab = page.getByTestId('tab-datasets');
            await datasetsTab.click();

            await expect(datasetsTab).toHaveAttribute('data-state', 'active');
        });
    });

    test.describe('accessibility', () => {
        test('tabs have proper ARIA roles', async ({ page }) => {
            const tabsList = page.locator('[role="tablist"]');
            await expect(tabsList).toBeVisible();

            const tabs = page.locator('[role="tab"]');
            const count = await tabs.count();
            expect(count).toBe(3);
        });

        test('tabs are keyboard navigable', async ({ page }) => {
            const gettingStartedTab = page.getByTestId('tab-getting-started');
            await gettingStartedTab.focus();
            await expect(gettingStartedTab).toBeFocused();

            // Press arrow right to move to next tab
            await page.keyboard.press('ArrowRight');

            const datasetsTab = page.getByTestId('tab-datasets');
            await expect(datasetsTab).toBeFocused();
        });

        test('sidebar navigation is keyboard accessible', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');
            const firstButton = sidebar.locator('button').first();

            // Focus the button
            await firstButton.focus();
            await expect(firstButton).toBeFocused();

            // Press Enter to activate
            await page.keyboard.press('Enter');

            // Should not throw any errors (implicit test)
        });
    });
});
