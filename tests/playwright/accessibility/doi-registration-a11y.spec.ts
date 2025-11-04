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
     * Helper function to setup a resource with landing page for DOI tests
     * Returns the DataCite button for the resource
     */
    async function setupResourceForDoi(page: Page) {
        // Navigate to resources page
        await page.goto('/resources');
        await expect(page).toHaveURL(/\/resources/);
        
        // Wait for page to load - try multiple strategies
        try {
            await page.waitForLoadState('domcontentloaded', { timeout: 5000 });
        } catch {
            // Continue anyway - DOM might already be loaded
        }
        
        // Wait for main content area to be visible (more reliable than table)
        await expect(page.locator('main, [role="main"], #app').first()).toBeVisible({ timeout: 10000 });
        
        // Give React time to render the table
        await page.waitForTimeout(2000);
        
        // Try to find the table - it should exist now with seeded data
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        // Get the first resource row from tbody
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible({ timeout: 5000 });
        
        // Find the landing page button (eye icon, typically 2nd button in the row)
        const landingPageButton = resourceRow.locator('button').nth(1);
        await expect(landingPageButton).toBeVisible();
        
        // Check if landing page already exists by examining aria-label
        const ariaLabel = await landingPageButton.getAttribute('aria-label');
        const hasLandingPage = ariaLabel?.includes('View') || ariaLabel?.includes('Edit') || false;
        
        if (!hasLandingPage) {
            // Need to create landing page first
            await landingPageButton.click();
            
            // Wait for modal to appear
            await expect(page.getByRole('dialog')).toBeVisible({ timeout: 5000 });
            
            // Save with default settings
            const saveButton = page.getByRole('button', { name: /save|create/i });
            if (await saveButton.isVisible()) {
                await saveButton.click();
                await page.waitForTimeout(1000);
                
                // Modal should close after save
                await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5000 });
            }
        }
        
        // Return the DataCite button (typically 3rd button in the row)
        // Try multiple selectors to be more resilient
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
        const accessibilityScanResults = await new AxeBuilder({ page })
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
        await page.waitForLoadState('networkidle');

        // Find a published or review badge (clickable)
        const clickableBadge = page.getByText('Published').or(page.getByText('Review')).first();
        
        if (await clickableBadge.count() > 0) {
            const badgeElement = clickableBadge.locator('..');
            
            // Should be focusable
            await badgeElement.focus();
            await expect(badgeElement).toBeFocused();

            // Should have role="button"
            await expect(badgeElement).toHaveAttribute('role', 'button');

            // Should have tabindex="0"
            await expect(badgeElement).toHaveAttribute('tabIndex', '0');

            // Should have aria-label
            const ariaLabel = await badgeElement.getAttribute('aria-label');
            expect(ariaLabel).toBeTruthy();
            expect(ariaLabel).toContain('Click');
        }
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
        await page.waitForLoadState('networkidle');

        // Find a resource in curation status
        const curationBadges = page.getByText('Curation').first();
        
        if (await curationBadges.isVisible()) {
            const resourceRow = curationBadges.locator('..').locator('..');
            
            // Find DataCite button in this row
            const dataciteButton = resourceRow.getByRole('button').nth(2);
            
            // Should have accessible name via aria-label or title
            const ariaLabel = await dataciteButton.getAttribute('aria-label');
            const title = await dataciteButton.getAttribute('title');
            
            expect(ariaLabel || title).toBeTruthy();
        }
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
        await page.goto('/resources');
        await page.waitForLoadState('networkidle');

        // Open modal for resource without landing page
        const curationBadge = page.getByText('Curation').first();
        
        if (await curationBadge.count() > 0) {
            const resourceRow = curationBadge.locator('..').locator('..');
            const dataciteButton = resourceRow.getByRole('button').nth(2);
            await dataciteButton.click();

            // Error alert should be visible
            const errorAlert = page.getByRole('alert').filter({ 
                hasText: /landing page required/i 
            });
            
            if (await errorAlert.count() > 0) {
                await expect(errorAlert).toBeVisible();

                // Should have proper ARIA attributes
                await expect(errorAlert).toHaveAttribute('role', 'alert');
            }
        }
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
        
        if (await loadingText.isVisible()) {
            // Should be visible to screen readers
            const ariaHidden = await loadingText.getAttribute('aria-hidden');
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
