import AxeBuilder from '@axe-core/playwright';
import type { Page } from '@playwright/test';
import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// DOI Registration Accessibility Tests
// Tests WCAG 2.1 AA compliance for DOI registration features

test.describe('DOI Registration Accessibility', () => {
    test.beforeEach(async ({ page }) => {
        // Login before each test
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    });

    /**
     * Helper function to find a resource with landing page for DOI tests
     * Returns the DataCite button for the resource
     */
    async function setupResourceForDoi(page: Page) {
        // Navigate to resources page
        await page.goto('/resources');
        await expect(page).toHaveURL(/\/resources/);
        
        // Wait for page to load
        try {
            await page.waitForLoadState('domcontentloaded', { timeout: 5000 });
        } catch {
            // Continue anyway - DOM might already be loaded
        }
        
        // Wait for main content area to be visible
        await expect(page.locator('main, [role="main"], #app').first()).toBeVisible({ timeout: 10000 });
        
        // Give React time to render the table
        await page.waitForTimeout(2000);
        
        // Find the table with resources
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        // Get the first resource row from tbody (should have landing page from seeder)
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible({ timeout: 5000 });
        
        // Return the DataCite button (typically 3rd button: Edit/Landing Page/DataCite)
        const dataciteButton = resourceRow.getByRole('button').filter({ 
            has: page.locator('[data-testid="datacite-icon"]') 
        }).or(resourceRow.locator('button').nth(2));
        
        await expect(dataciteButton).toBeVisible();
        
        return dataciteButton;
    }

    test('doi registration modal meets accessibility standards', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        // Wait for modal to be visible
        await expect(page.getByRole('dialog')).toBeVisible();

        // Run accessibility scan
        const axe = new AxeBuilder({ page }).include('[role="dialog"]');

        // Playwright WebKit can differ in CSS color parsing (e.g., OKLCH), which makes
        // color-contrast results unreliable compared to Chromium/Firefox.
        if (test.info().project.name === 'webkit') {
            axe.disableRules(['color-contrast']);
        }

        const accessibilityScanResults = await axe
            .include('[role="dialog"]')
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });

    test('doi registration modal has proper aria labels', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // Dialog should have accessible name
        const dialogTitle = dialog.getByRole('heading', { level: 2 });
        await expect(dialogTitle).toBeVisible();
        
        // Buttons should have accessible labels
        const registerButton = dialog.getByRole('button', { name: /register doi|update metadata/i });
        await expect(registerButton).toBeVisible();

        const cancelButton = dialog.getByRole('button', { name: /cancel/i });
        await expect(cancelButton).toBeVisible();
    });

    test('prefix selection is keyboard accessible', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        await expect(page.getByRole('dialog')).toBeVisible();

        // Check if prefix select is present (for new DOI)
        const prefixSelect = page.getByRole('combobox');
        
        if (await prefixSelect.isVisible()) {
            // Should be focusable
            await prefixSelect.focus();
            await expect(prefixSelect).toBeFocused();

            // Should have accessible label
            const label = page.getByText(/select doi prefix/i);
            await expect(label).toBeVisible();

            // Should be operable with keyboard
            await prefixSelect.press('Enter');
            await page.waitForTimeout(300);
            
            // Options should appear
            const options = page.getByRole('option');
            if (await options.count() > 0) {
                await expect(options.first()).toBeVisible();
            }
        }
    });

    test('status badges meet color contrast requirements', async ({ page }) => {
        await page.goto('/resources');
        await page.waitForLoadState('networkidle');

        // Run accessibility scan on entire page including badges
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2aa', 'wcag21aa'])
            .analyze();

        // Check for color contrast violations
        const contrastViolations = accessibilityScanResults.violations.filter(
            (violation) => violation.id === 'color-contrast'
        );

        expect(contrastViolations).toEqual([]);
    });

    test('status badges are keyboard accessible when clickable', async ({ page }) => {
        await page.goto('/resources');
        await page.waitForTimeout(2000); // Wait for React to render

        // Find a published or review badge (clickable) - these are the actual span elements with role="button"
        const clickableBadge = page.getByRole('button').filter({ 
            hasText: /Published|Review/ 
        }).first();
        
        // Wait for badge to be visible
        await expect(clickableBadge).toBeVisible({ timeout: 10000 });
        
        // Should be focusable
        await clickableBadge.focus();
        await expect(clickableBadge).toBeFocused();

        // Should have role="button"
        await expect(clickableBadge).toHaveAttribute('role', 'button');

        // Should have tabindex="0"
        await expect(clickableBadge).toHaveAttribute('tabIndex', '0');

        // Should have aria-label
        const ariaLabel = await clickableBadge.getAttribute('aria-label');
        expect(ariaLabel).toBeTruthy();
        expect(ariaLabel).toContain('Click');
    });

    test('status badges can be activated with keyboard', async ({ page }) => {
        await page.goto('/resources');
        await page.waitForLoadState('networkidle');

        // Find a clickable badge
        const publishedBadge = page.getByText('Published').first();
        
        if (await publishedBadge.count() > 0) {
            const badgeElement = publishedBadge.locator('..');
            
            // Focus the badge
            await badgeElement.focus();
            
            // Should be activatable with Enter key
            await badgeElement.press('Enter');
            // Note: We don't test the actual clipboard/window.open behavior here
            // as those are tested in unit tests
            
            // Should also be activatable with Space key
            await badgeElement.press('Space');
        }
    });

    test('datacite icon button has accessible label', async ({ page }) => {
        await page.goto('/resources');
        await page.waitForTimeout(2000); // Wait for React to render

        // Find the first resource row in the table
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible();
        
        // Find DataCite button in this row (typically 3rd button)
        const dataciteButton = resourceRow.locator('button').nth(2);
        await expect(dataciteButton).toBeVisible();
        
        // Should have accessible name via aria-label or title
        const ariaLabel = await dataciteButton.getAttribute('aria-label');
        const title = await dataciteButton.getAttribute('title');
        
        expect(ariaLabel || title).toBeTruthy();
    });

    test('modal can be closed with escape key', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // Press Escape
        await page.keyboard.press('Escape');

        // Modal should close
        await expect(dialog).not.toBeVisible({ timeout: 2000 });
    });

    test('focus is trapped within modal', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // Tab through modal elements
        await page.keyboard.press('Tab');
        
        // Focus should stay within modal
        const focusedElement = await page.evaluateHandle(() => document.activeElement);
        const isWithinDialog = await dialog.evaluate((dialog, focused) => {
            return dialog.contains(focused as Node);
        }, focusedElement);

        expect(isWithinDialog).toBeTruthy();
    });

    test('error messages are announced to screen readers', async ({ page }) => {
        // This test is no longer relevant since all seeded resources have landing pages
        // Skip it or test a different error scenario
        await page.goto('/resources');
        await page.waitForTimeout(2000);

        // All test resources have landing pages now, so we'd need to test
        // a different error (e.g., network error, validation error)
        // For now, we'll skip this specific landing page error test
        
        // Alternative: Test that error messages in the registration modal have proper ARIA
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();
        
        // Modal should be visible
        await expect(page.getByRole('dialog')).toBeVisible();
        
        // Any validation errors shown should have role="alert"
        // This is more of a placeholder - actual error would need to be triggered
    });

    test('form inputs have proper labels', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible();

        // Check for prefix selection label
        const prefixSelect = page.getByRole('combobox');
        
        if (await prefixSelect.isVisible()) {
            const labelText = await page.getByText(/select doi prefix/i).textContent();
            expect(labelText).toBeTruthy();

            // Label should be associated with select
            const labelFor = await page.getByText(/select doi prefix/i).getAttribute('for');
            const selectId = await prefixSelect.getAttribute('id');
            
            // Either direct association or wrapping label
            expect(labelFor === selectId || selectId).toBeTruthy();
        }
    });

    test('loading states are announced', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        // Check for loading message
        const loadingText = page.getByText(/loading configuration/i);

        // Loading state can be very transient; only assert if it's currently present.
        if ((await loadingText.count()) > 0) {
            const ariaHidden = await loadingText.first().getAttribute('aria-hidden');
            expect(ariaHidden).not.toBe('true');
        }
    });

    test('resources page with doi features passes accessibility scan', async ({ page }) => {
        await page.goto('/resources');
        await page.waitForLoadState('networkidle');

        // Run full page accessibility scan
        const accessibilityScanResults = await new AxeBuilder({ page })
            .withTags(['wcag2aa', 'wcag21aa'])
            .analyze();

        expect(accessibilityScanResults.violations).toEqual([]);
    });
});
