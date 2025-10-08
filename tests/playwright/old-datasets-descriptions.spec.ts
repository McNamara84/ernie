import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests for loading descriptions from old datasets.
 * 
 * IMPORTANT: These tests require:
 * - A running Laravel server (php artisan serve)
 * - A working legacy database with test data
 * - A verified test user
 * 
 * The tests are marked with .skip as they require complete infrastructure.
 * Remove .skip to run the tests in a test environment.
 */

test.describe('Load descriptions from old datasets', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    });

    test.skip('loads Abstract from old datasets into curation form', async ({ page }) => {
        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);
        await expect(page.getByRole('heading', { name: 'Old Datasets' })).toBeVisible();

        // Find the first dataset with "Open in Curation" button
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for redirect to curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });
        await expect(page).toHaveURL(/\/curation/);

        // Verify that the form has loaded
        await expect(page.getByRole('heading', { name: 'Create Resource' })).toBeVisible();

        // Open Descriptions Accordion if not already open
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Verify that the Abstract tab is present
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await expect(abstractTab).toBeVisible();

        // Click on Abstract tab (if not already active)
        await abstractTab.click();

        // Verify that the Abstract textarea has loaded and has content
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        await expect(abstractTextarea).toBeVisible();
        
        // Verify that the Abstract is not empty (as old datasets usually have abstracts)
        const abstractValue = await abstractTextarea.inputValue();
        expect(abstractValue.length).toBeGreaterThan(0);
    });

    test.skip('loads all description types from old datasets correctly', async ({ page }) => {
        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Find the first dataset
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Open Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // List of description types from the old DB
        const descriptionTypes = ['Abstract', 'Methods', 'TechnicalInfo', 'TableOfContents', 'Other'];

        // Check each tab
        for (const descType of descriptionTypes) {
            const tab = page.getByRole('tab', { name: new RegExp(descType, 'i') });
            
            if (await tab.isVisible()) {
                // Click on the tab
                await tab.click();

                // Check if the tab has a badge (indicates content is present)
                const hasBadge = await tab.locator('.badge, [class*="badge"]').count() > 0;

                if (hasBadge) {
                    // If badge is present, the textarea should have content
                    const textarea = page.getByRole('textbox', { name: new RegExp(descType, 'i') });
                    await expect(textarea).toBeVisible();
                    
                    const content = await textarea.inputValue();
                    expect(content.length).toBeGreaterThan(0);
                }
            }
        }
    });

    test.skip('shows character count for loaded descriptions', async ({ page }) => {
        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Find the first dataset
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Open Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Click on Abstract tab
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await abstractTab.click();

        // Verify that character count is displayed
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        const abstractValue = await abstractTextarea.inputValue();

        if (abstractValue.length > 0) {
            // Character count should be visible and show the correct number
            const characterCountText = page.getByText(new RegExp(`${abstractValue.length} characters`));
            await expect(characterCountText).toBeVisible();
        }
    });

    test.skip('retains description data when switching between tabs', async ({ page }) => {
        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Find the first dataset
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Open Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Save Abstract value
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await abstractTab.click();
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        const originalAbstractValue = await abstractTextarea.inputValue();

        // Switch to another tab
        const methodsTab = page.getByRole('tab', { name: /Methods/i });
        if (await methodsTab.isVisible()) {
            await methodsTab.click();
        }

        // Switch back to Abstract
        await abstractTab.click();

        // Verify that the original value is still present
        const currentAbstractValue = await abstractTextarea.inputValue();
        expect(currentAbstractValue).toBe(originalAbstractValue);
    });

    test.skip('loads datasets without descriptions gracefully', async ({ page }) => {
        // This test verifies that the form still works correctly
        // when an old dataset has no descriptions

        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Find a dataset (could be without descriptions)
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Open Descriptions Accordion
        const descriptionsTrigger = page.getByRole('button', { name: 'Descriptions' });
        const isExpanded = await descriptionsTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await descriptionsTrigger.click();
            await expect(descriptionsTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Verify that all tabs are present (even if empty)
        const abstractTab = page.getByRole('tab', { name: /Abstract/i });
        await expect(abstractTab).toBeVisible();

        // The form should be usable even if no descriptions were loaded
        await abstractTab.click();
        const abstractTextarea = page.getByRole('textbox', { name: /Abstract/i });
        await expect(abstractTextarea).toBeVisible();
        await expect(abstractTextarea).toBeEnabled();
    });
});
