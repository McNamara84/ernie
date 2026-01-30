import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Font Size Toggle E2E Tests
 *
 * Tests the font size toggle functionality including:
 * - UI interaction with the toggle button
 * - CSS class changes on the HTML element
 * - Accessibility attributes
 *
 * Note: These tests are state-independent - they check that clicking the toggle
 * changes the state, regardless of what the initial state is.
 */

test.describe('Font Size Toggle', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);

        // Wait for page to stabilize
        await page.waitForLoadState('networkidle');
    });

    test('toggle button changes font-large class on html element', async ({ page }) => {
        const htmlElement = page.locator('html');
        const toggleButton = page.getByTestId('font-size-quick-toggle');

        // Get initial state
        const initialClass = (await htmlElement.getAttribute('class')) ?? '';
        const wasLarge = initialClass.includes('font-large');

        // Click the font size toggle button
        await expect(toggleButton).toBeVisible();
        await toggleButton.click();

        // Verify class changed
        if (wasLarge) {
            await expect(htmlElement).not.toHaveClass(/font-large/);
        } else {
            await expect(htmlElement).toHaveClass(/font-large/);
        }
    });

    test('font size toggle button has correct accessibility attributes', async ({ page }) => {
        const toggleButton = page.getByTestId('font-size-quick-toggle');

        // Check aria-label contains expected text pattern
        const ariaLabel = await toggleButton.getAttribute('aria-label');
        expect(ariaLabel).toMatch(/Font size: (Regular|Large)\. Click to switch to (regular|large) font size\./);
    });

    test('font size toggle is accessible via keyboard', async ({ page }) => {
        const htmlElement = page.locator('html');
        const toggleButton = page.getByTestId('font-size-quick-toggle');

        // Get initial state
        const initialClass = (await htmlElement.getAttribute('class')) ?? '';
        const wasLarge = initialClass.includes('font-large');

        // Focus the toggle button and press Enter
        await toggleButton.focus();
        await page.keyboard.press('Enter');

        // Verify class changed
        if (wasLarge) {
            await expect(htmlElement).not.toHaveClass(/font-large/);
        } else {
            await expect(htmlElement).toHaveClass(/font-large/);
        }
    });

    test('settings page has font size toggle option', async ({ page }) => {
        await page.goto('/settings/appearance');

        // Verify the font size toggle group is present
        const fontSizeGroup = page.locator('[role="group"][aria-label="Font size options"]');
        await expect(fontSizeGroup).toBeVisible();

        // Verify both options are present
        await expect(page.locator('[aria-label="Font size options"] button', { hasText: 'Regular' })).toBeVisible();
        await expect(page.locator('[aria-label="Font size options"] button', { hasText: 'Large' })).toBeVisible();
    });
});
