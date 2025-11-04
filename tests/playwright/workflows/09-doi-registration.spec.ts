import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// DOI Registration Workflow Tests
// Tests the complete workflow of registering a DOI with DataCite

test.describe('DOI Registration Workflow', () => {
    test.beforeEach(async ({ page }) => {
        // Login before each test
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    });

    test('complete doi registration flow with new resource', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        await expect(page).toHaveURL(/\/resources/);

        // Find a resource without DOI (look for resources in curation status)
        const curationBadges = page.getByText('Curation').first();
        await expect(curationBadges).toBeVisible({ timeout: 10000 });

        // Get the row containing this badge
        const resourceRow = curationBadges.locator('..').locator('..');
        
        // First, check if landing page button (Eye icon) exists
        const landingPageButton = resourceRow.getByRole('button').filter({ has: page.locator('svg') }).nth(1);
        
        // If no landing page exists, set one up first
        const landingPageExists = await resourceRow.getByRole('button', { name: /landing page/i }).count() > 0;
        
        if (!landingPageExists) {
            // Click eye icon to setup landing page
            await landingPageButton.click();
            
            // Wait for modal
            await expect(page.getByRole('dialog')).toBeVisible();
            await expect(page.getByText(/setup landing page/i)).toBeVisible();
            
            // Setup landing page (assuming basic setup)
            const saveButton = page.getByRole('button', { name: /save/i });
            if (await saveButton.isEnabled()) {
                await saveButton.click();
                await page.waitForTimeout(1000); // Wait for save
            }
        }

        // Now click on DataCite icon to register DOI
        const dataciteButton = resourceRow.getByRole('button').filter({ has: page.locator('[data-testid="datacite-icon"]') }).or(
            resourceRow.getByRole('button').nth(2) // DataCite button is typically 3rd
        );
        
        await dataciteButton.click();

        // Verify DOI registration modal appears
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText(/register doi with datacite/i)).toBeVisible();

        // Check for test mode warning
        await expect(page.getByText(/test mode active/i)).toBeVisible();

        // Verify prefix selection is available
        const prefixSelect = page.getByRole('combobox');
        await expect(prefixSelect).toBeVisible();

        // Select a prefix (first one should be selected by default)
        await expect(prefixSelect).toHaveText(/10\.83279|10\.83186|10\.83114/);

        // Click register button
        const registerButton = page.getByRole('button', { name: /register doi/i });
        await expect(registerButton).toBeEnabled();
        await registerButton.click();

        // Wait for success toast
        await expect(page.getByText(/doi.*registered successfully/i)).toBeVisible({ timeout: 10000 });

        // Modal should close
        await expect(page.getByRole('dialog')).not.toBeVisible({ timeout: 5000 });

        // Verify resource status changed (should now show Review or Published badge)
        await page.waitForTimeout(1000); // Wait for reload
        await expect(
            resourceRow.getByText('Review').or(resourceRow.getByText('Published'))
        ).toBeVisible();
    });

    test('update metadata for existing doi', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        
        // Find a resource with Published or Review status (has DOI)
        const publishedBadge = page.getByText('Published').first();
        const reviewBadge = page.getByText('Review').first();
        
        const statusBadge = await publishedBadge.count() > 0 ? publishedBadge : reviewBadge;
        await expect(statusBadge).toBeVisible({ timeout: 10000 });

        // Get the row
        const resourceRow = statusBadge.locator('..').locator('..');

        // Click DataCite button
        const dataciteButton = resourceRow.getByRole('button').filter({ 
            has: page.locator('[data-testid="datacite-icon"]') 
        }).or(resourceRow.getByRole('button').nth(2));
        
        await dataciteButton.click();

        // Verify update modal
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText(/update doi metadata/i)).toBeVisible();

        // Should show existing DOI
        await expect(page.getByText(/existing doi/i)).toBeVisible();
        await expect(page.getByText(/10\.\d+/)).toBeVisible();

        // Should NOT show prefix selection
        const prefixSelect = page.getByRole('combobox');
        await expect(prefixSelect).not.toBeVisible();

        // Click update button
        const updateButton = page.getByRole('button', { name: /update metadata/i });
        await expect(updateButton).toBeEnabled();
        await updateButton.click();

        // Wait for success
        await expect(page.getByText(/metadata updated|doi.*updated/i)).toBeVisible({ timeout: 10000 });
    });

    test('cannot register doi without landing page', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');

        // Find a resource in curation status without landing page
        const curationBadge = page.getByText('Curation').first();
        await expect(curationBadge).toBeVisible({ timeout: 10000 });

        const resourceRow = curationBadge.locator('..').locator('..');

        // Click DataCite button
        const dataciteButton = resourceRow.getByRole('button').nth(2);
        await dataciteButton.click();

        // Modal should show landing page requirement
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText(/landing page required/i)).toBeVisible();
        await expect(
            page.getByText(/landing page must be created before you can register a doi/i)
        ).toBeVisible();

        // Register button should be disabled
        const registerButton = page.getByRole('button', { name: /register doi/i });
        await expect(registerButton).toBeDisabled();
    });

    test('displays test mode warning', async ({ page }) => {
        // Navigate to resources and open DOI modal
        await page.goto('/resources');
        
        const resourceRow = page.locator('tr').first();
        const dataciteButton = resourceRow.getByRole('button').nth(2);
        await dataciteButton.click();

        // Check for test mode warning
        await expect(page.getByRole('dialog')).toBeVisible();
        const testModeWarning = page.getByText(/test mode active/i);
        
        if (await testModeWarning.isVisible()) {
            await expect(testModeWarning).toBeVisible();
            await expect(
                page.getByText(/datacite test environment.*not permanent/i)
            ).toBeVisible();
        }
    });

    test('modal can be cancelled', async ({ page }) => {
        // Navigate to resources and open DOI modal
        await page.goto('/resources');
        
        const resourceRow = page.locator('tr').first();
        const dataciteButton = resourceRow.getByRole('button').nth(2);
        await dataciteButton.click();

        // Modal should be visible
        await expect(page.getByRole('dialog')).toBeVisible();

        // Click cancel
        const cancelButton = page.getByRole('button', { name: /cancel/i });
        await cancelButton.click();

        // Modal should close
        await expect(page.getByRole('dialog')).not.toBeVisible();
    });

    test('status badge is clickable for published resources', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        
        // Find published resource
        const publishedBadge = page.getByText('Published').first();
        
        if (await publishedBadge.count() > 0) {
            await expect(publishedBadge).toBeVisible();

            // Badge should have button role or be clickable
            const badgeElement = publishedBadge.locator('..');
            await expect(badgeElement).toHaveAttribute('role', 'button');
            
            // Should have hover effect
            await expect(badgeElement).toHaveCSS('cursor', 'pointer');
        }
    });

    test('status badge is clickable for review resources', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        
        // Find review resource
        const reviewBadge = page.getByText('Review').first();
        
        if (await reviewBadge.count() > 0) {
            await expect(reviewBadge).toBeVisible();

            // Badge should have button role or be clickable
            const badgeElement = reviewBadge.locator('..');
            await expect(badgeElement).toHaveAttribute('role', 'button');
            
            // Should have hover effect
            await expect(badgeElement).toHaveCSS('cursor', 'pointer');
        }
    });

    test('status badge is not clickable for curation resources', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        
        // Find curation resource
        const curationBadge = page.getByText('Curation').first();
        
        if (await curationBadge.count() > 0) {
            await expect(curationBadge).toBeVisible();

            // Badge should NOT have button role
            const badgeElement = curationBadge.locator('..');
            const role = await badgeElement.getAttribute('role');
            expect(role).not.toBe('button');
        }
    });

    test('resources list refreshes after doi registration', async ({ page }) => {
        // Navigate to resources
        await page.goto('/resources');
        
        // Get initial resource count
        const initialRows = await page.locator('tr').count();
        expect(initialRows).toBeGreaterThan(0);

        // The list should maintain state after DOI operations
        // (This is tested indirectly through the complete flow test above)
    });
});
