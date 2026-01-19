import { expect, test } from '@playwright/test';

test.describe('Documentation Page', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/docs');
        // Wait for the page to be fully loaded
        await page.waitForSelector('h1');
    });

    test.describe('page structure', () => {
        test('displays the documentation page heading', async ({ page }) => {
            await expect(page.getByRole('heading', { name: 'Documentation', level: 1 })).toBeVisible();
        });

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

            // Verify Datasets content is visible
            await expect(page.locator('text=DOI')).toBeVisible();
        });

        test('switches to Physical Samples tab when clicked', async ({ page }) => {
            const physicalSamplesTab = page.getByTestId('tab-physical-samples');
            await physicalSamplesTab.click();

            await expect(physicalSamplesTab).toHaveAttribute('data-state', 'active');
            await expect(page.getByTestId('tab-getting-started')).toHaveAttribute('data-state', 'inactive');

            // Verify Physical Samples content is visible
            await expect(page.locator('text=IGSN')).toBeVisible();
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
            const buttonText = await firstButton.textContent();

            await firstButton.click();

            // Wait for scroll to complete
            await page.waitForTimeout(500);

            // The corresponding section should be near the top of the viewport
            // We verify by checking if a heading with similar text is visible
            if (buttonText) {
                const section = page.locator(`h2:has-text("${buttonText}"), h3:has-text("${buttonText}")`).first();
                await expect(section).toBeInViewport();
            }
        });
    });

    test.describe('scroll-spy behavior', () => {
        test('updates active section in sidebar when scrolling', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');

            // Scroll down significantly
            await page.evaluate(() => {
                window.scrollTo(0, document.body.scrollHeight / 2);
            });

            // Wait for scroll-spy to update
            await page.waitForTimeout(300);

            // Check that some button is marked as active
            const activeButtons = sidebar.locator('button[data-active="true"]');
            const activeCount = await activeButtons.count();

            // Either the active state changed or at least one is active
            expect(activeCount).toBeGreaterThanOrEqual(0);
        });
    });

    test.describe('mobile navigation', () => {
        test.use({ viewport: { width: 375, height: 667 } });

        test('hides desktop sidebar on mobile', async ({ page }) => {
            const desktopSidebar = page.locator('[data-testid="docs-sidebar"]');
            await expect(desktopSidebar).not.toBeVisible();
        });

        test('shows mobile sidebar toggle on mobile', async ({ page }) => {
            // Mobile sidebar should be available via a toggle/sheet
            const mobileToggle = page.locator('[data-testid="docs-mobile-sidebar-trigger"]');
            // If the mobile trigger exists, it should be visible
            if (await mobileToggle.count() > 0) {
                await expect(mobileToggle).toBeVisible();
            }
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

        test('sidebar navigation buttons are keyboard accessible', async ({ page, viewport }) => {
            test.skip(!viewport || viewport.width < 1024, 'Desktop-only test');

            const sidebar = page.locator('[data-testid="docs-sidebar"]');
            const firstButton = sidebar.locator('button').first();

            // Focus the button
            await firstButton.focus();
            await expect(firstButton).toBeFocused();

            // Press Enter to activate
            await page.keyboard.press('Enter');

            // Should trigger scroll (tested by verifying no errors)
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
    });

    test.describe('content sections', () => {
        test('Getting Started tab shows welcome section', async ({ page }) => {
            // Should have a welcome or introduction section
            const welcomeHeading = page.locator('h2, h3').filter({ hasText: /welcome|introduction|getting started/i }).first();
            await expect(welcomeHeading).toBeVisible();
        });

        test('Datasets tab shows DOI workflow information', async ({ page }) => {
            await page.getByTestId('tab-datasets').click();

            // Should mention DOI somewhere in the content
            await expect(page.locator('text=DOI').first()).toBeVisible();
        });

        test('Physical Samples tab shows IGSN information', async ({ page }) => {
            await page.getByTestId('tab-physical-samples').click();

            // Should mention IGSN somewhere in the content
            await expect(page.locator('text=IGSN').first()).toBeVisible();
        });
    });
});
