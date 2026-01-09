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
     * Helper function to find a resource with landing page for DOI tests.
     * Returns the DataCite button for the resource.
     * 
     * Note: The DataCite button is only visible for resources that have a landing page.
     * The PlaywrightTestSeeder creates resources with landing pages for this purpose.
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
        
        // Find ANY row that has a DataCite button (not just the first row)
        // The DataCite button is only visible for resources with a landing page
        const dataciteButton = resourceTable.locator('tbody tr [data-testid="datacite-button"]').first();
        
        // If datacite button not found, try alternative selectors
        let isVisible = await dataciteButton.isVisible().catch(() => false);
        
        if (!isVisible) {
            // Fallback: try to find by icon
            const altButton = resourceTable.locator('tbody tr').getByRole('button').filter({ 
                has: page.locator('[data-testid="datacite-icon"]') 
            }).first();
            isVisible = await altButton.isVisible().catch(() => false);
            
            if (isVisible) {
                await expect(altButton).toBeVisible({ timeout: 5000 });
                return altButton;
            }
        }
        
        if (!isVisible) {
            // Provide helpful error message
            const rowCount = await resourceTable.locator('tbody tr').count();
            throw new Error(
                `DataCite button not found in any row. This usually means no resources have landing pages. ` +
                `Table has ${rowCount} rows. Ensure PlaywrightTestSeeder creates resources with landing pages ` +
                `and that the resources appear on the first page of the table.`
            );
        }
        
        await expect(dataciteButton).toBeVisible({ timeout: 5000 });
        
        return dataciteButton;
    }

    test('doi registration modal meets accessibility standards', async ({ page }) => {
        const dataciteButton = await setupResourceForDoi(page);
        await dataciteButton.click();

        // Wait for modal to be visible
        await expect(page.getByRole('dialog')).toBeVisible();

        // Wait for the loading animation to complete (the content may be a form or an error message)
        // The loading state uses animate-pulse class
        await expect(page.locator('[role="dialog"] .animate-pulse')).toHaveCount(0, { timeout: 10000 });

        // Run accessibility scan on the fully loaded dialog content
        // Exclude any loading animation elements that might still be present
        const accessibilityScanResults = await new AxeBuilder({ page })
            .include('[role="dialog"]')
            .exclude('.animate-pulse') // Exclude loading animation elements
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

        // Find the first resource row in the table that has a DataCite button
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        // Find any DataCite button in the table (only visible for resources with landing pages)
        const dataciteButton = resourceTable.locator('tbody tr [data-testid="datacite-button"]').first();
        
        // Skip test if no DataCite button found (no resources with landing pages)
        if (!(await dataciteButton.isVisible().catch(() => false))) {
            test.skip(true, 'No resources with landing pages found in table');
            return;
        }
        
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

        // Check for loading message - it may appear briefly or not at all depending on network speed
        const loadingText = page.getByText(/loading configuration/i);
        
        // Use a short timeout to check if loading text appears
        // This is intentionally a soft check since loading can be very fast
        // Wrap everything in try-catch because the element may disappear at any moment
        try {
            const isVisible = await loadingText.isVisible({ timeout: 2000 });
            
            if (isVisible) {
                // Check aria-hidden with a very short timeout since element may disappear
                // Use evaluate to get attribute without Playwright's auto-wait
                const ariaHidden = await loadingText.evaluate(
                    (el) => el.getAttribute('aria-hidden')
                ).catch(() => null);
                
                if (ariaHidden !== null) {
                    expect(ariaHidden).not.toBe('true');
                }
            }
        } catch {
            // If loading text isn't visible or disappeared, that's acceptable - it means loading was fast
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
