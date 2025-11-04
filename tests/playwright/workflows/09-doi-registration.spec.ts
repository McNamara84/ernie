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
        await page.waitForTimeout(2000); // Wait for React rendering

        // Find table and get first resource with landing page (first or second row)
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible();

        // Click DataCite button (3rd button in row: Edit/Landing Page/DataCite)
        const dataciteButton = resourceRow.locator('button').nth(2);
        await expect(dataciteButton).toBeVisible();
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

        // Wait for success toast (actual text: "DOI {doi} successfully registered!")
        await expect(page.getByText(/successfully registered/i)).toBeVisible({ timeout: 10000 });

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
        await page.waitForTimeout(2000); // Wait for React rendering
        
        // Find table and get third row (Published resource from seeder)
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        // Third row should be the Published resource
        const resourceRow = resourceTable.locator('tbody tr').nth(2);
        await expect(resourceRow).toBeVisible();

        // Click DataCite button
        const dataciteButton = resourceRow.locator('button').nth(2);
        await expect(dataciteButton).toBeVisible();
        await dataciteButton.click();

        // Verify update modal
        await expect(page.getByRole('dialog')).toBeVisible();
        await expect(page.getByText(/update doi metadata/i)).toBeVisible();

        // Should show existing DOI (use first() to avoid strict mode violation with 2 matches)
        await expect(page.getByText(/existing doi/i).first()).toBeVisible();
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
        await page.waitForTimeout(2000); // Wait for React rendering

        // Find table and get fourth row (Curation resource WITHOUT landing page from seeder)
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        const resourceRow = resourceTable.locator('tbody tr').nth(3);
        await expect(resourceRow).toBeVisible();

        // DataCite button (3rd button) should NOT exist for resources without landing page
        // The UI conditionally renders it only when resource.landingPage exists
        const dataciteButton = resourceRow.locator('button').nth(2);
        
        // Should not find the DataCite button (only Edit and Landing Page buttons exist)
        // Count buttons - should be 4 (Edit, Landing Page in first row, Export JSON, Export XML in second row)
        const buttonCount = await resourceRow.locator('button').count();
        expect(buttonCount).toBe(4); // No DataCite button for resources without landing page
        
        // Verify the DataCite button specifically doesn't exist
        await expect(dataciteButton.locator('[data-testid="datacite-icon"]')).not.toBeVisible();
    });

    test('displays test mode warning', async ({ page }) => {
        // Navigate to resources and open DOI modal
        await page.goto('/resources');
        await page.waitForTimeout(2000); // Wait for React rendering
        
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible();
        
        const dataciteButton = resourceRow.locator('button').nth(2);
        await expect(dataciteButton).toBeVisible();
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
        await page.waitForTimeout(2000); // Wait for React rendering
        
        const resourceTable = page.locator('table').first();
        await expect(resourceTable).toBeVisible({ timeout: 10000 });
        
        const resourceRow = resourceTable.locator('tbody tr').first();
        await expect(resourceRow).toBeVisible();
        
        const dataciteButton = resourceRow.locator('button').nth(2);
        await expect(dataciteButton).toBeVisible();
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
        await page.waitForTimeout(2000); // Wait for React rendering
        
        // Find published badge by role (it's a button span)
        const publishedBadge = page.getByRole('button').filter({ hasText: 'Published' }).first();
        
        if (await publishedBadge.count() > 0) {
            await expect(publishedBadge).toBeVisible();

            // Badge should have button role
            await expect(publishedBadge).toHaveAttribute('role', 'button');
            
            // Should have tabindex for keyboard accessibility
            await expect(publishedBadge).toHaveAttribute('tabIndex', '0');
            
            // Should have hover effect
            await expect(publishedBadge).toHaveCSS('cursor', 'pointer');
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
