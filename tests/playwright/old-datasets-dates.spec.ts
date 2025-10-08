import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests for loading dates from old datasets.
 * 
 * IMPORTANT: These tests require:
 * - A running Laravel server (php artisan serve)
 * - A working legacy database with test data
 * - A verified test user
 * 
 * The tests are marked with .skip as they require complete infrastructure.
 * Remove .skip to run the tests in a test environment.
 */

test.describe('Load dates from old datasets', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    });

    test.skip('loads Created date from old datasets into curation form', async ({ page }) => {
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

        // Open Dates Accordion if not already open
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Verify that the Created date field is present and has a value
        // Created is the required date type (always present)
        const createdDateInput = page.getByLabel(/Created/i).first();
        await expect(createdDateInput).toBeVisible();
        
        // Check if the Created date has loaded from the old dataset
        const createdValue = await createdDateInput.inputValue();
        // Most old datasets should have a Created date
        if (createdValue.length > 0) {
            // Verify that the date is in a valid format
            expect(createdValue).toMatch(/^\d{4}(-\d{2}(-\d{2})?)?(\/\d{4}(-\d{2}(-\d{2})?)?)?$/);
        }
    });

    test.skip('loads multiple date types from old datasets correctly', async ({ page }) => {
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

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Count how many date entries are loaded
        const dateEntries = page.locator('[data-testid*="date-entry"], .date-entry, [class*="date-entry"]');
        const dateCount = await dateEntries.count();

        // Verify that at least one date is present (Created is always required)
        expect(dateCount).toBeGreaterThanOrEqual(1);

        // If multiple dates are present, verify they are visible
        if (dateCount > 1) {
            for (let i = 0; i < dateCount; i++) {
                const dateEntry = dateEntries.nth(i);
                await expect(dateEntry).toBeVisible();
            }
        }
    });

    test.skip('loads date ranges correctly (start/end format)', async ({ page }) => {
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

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Find all date input fields
        const dateInputs = page.locator('input[type="text"][name*="dates"]');
        const inputCount = await dateInputs.count();

        // Check each date input for range format (start/end)
        for (let i = 0; i < inputCount; i++) {
            const dateInput = dateInputs.nth(i);
            const dateValue = await dateInput.inputValue();

            if (dateValue.includes('/')) {
                // This is a date range - verify format
                const parts = dateValue.split('/');
                expect(parts.length).toBe(2);
                
                // Each part should be a valid date or empty (for open ranges)
                parts.forEach(part => {
                    if (part.length > 0) {
                        expect(part).toMatch(/^\d{4}(-\d{2}(-\d{2})?)?$/);
                    }
                });
            }
        }
    });

    test.skip('preserves date data when switching between accordions', async ({ page }) => {
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

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Save Created date value
        const createdDateInput = page.getByLabel(/Created/i).first();
        const originalCreatedValue = await createdDateInput.inputValue();

        // Close Dates accordion and open another one (e.g., Titles)
        await datesTrigger.click();
        await expect(datesTrigger).toHaveAttribute('aria-expanded', 'false');

        const titlesTrigger = page.getByRole('button', { name: 'Titles' });
        await titlesTrigger.click();
        await expect(titlesTrigger).toHaveAttribute('aria-expanded', 'true');

        // Switch back to Dates
        await titlesTrigger.click();
        await datesTrigger.click();
        await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');

        // Verify that the original value is still present
        const currentCreatedValue = await createdDateInput.inputValue();
        expect(currentCreatedValue).toBe(originalCreatedValue);
    });

    test.skip('loads datasets without dates gracefully', async ({ page }) => {
        // This test verifies that the form still works correctly
        // when an old dataset has no dates (except Created which is always added)

        // Navigate to Old Datasets page
        await page.goto('/old-datasets');
        await expect(page).toHaveURL(/\/old-datasets$/);

        // Find a dataset (could be without dates)
        const firstDatasetRow = page.locator('tbody tr').first();
        await firstDatasetRow.waitFor({ state: 'visible', timeout: 10_000 });

        // Click on "Open in Curation"
        const openButton = firstDatasetRow.getByRole('button', { name: /Open dataset/ });
        await openButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 15_000 });

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Verify that the Created date field is present
        const createdDateInput = page.getByLabel(/Created/i).first();
        await expect(createdDateInput).toBeVisible();

        // The form should be usable even if no dates were loaded
        await expect(createdDateInput).toBeEnabled();
        
        // User should be able to add a date
        await createdDateInput.fill('2024-01-01');
        const filledValue = await createdDateInput.inputValue();
        expect(filledValue).toBe('2024-01-01');
    });

    test.skip('displays correct date type options from old database', async ({ page }) => {
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

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        const isExpanded = await datesTrigger.getAttribute('aria-expanded');
        if (isExpanded === 'false') {
            await datesTrigger.click();
            await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // DataCite date types that should match the old database
        const expectedDateTypes = [
            'Accepted',
            'Available',
            'Copyrighted',
            'Collected',
            'Created',
            'Issued',
            'Submitted',
            'Updated',
            'Valid',
            'Withdrawn',
            'Other'
        ];

        // If there's an "Add Date" button, click it to see the dropdown
        const addDateButton = page.getByRole('button', { name: /Add Date/i });
        if (await addDateButton.isVisible() && await addDateButton.isEnabled()) {
            await addDateButton.click();
        }

        // Find the first date type select/dropdown
        const dateTypeSelect = page.locator('select[name*="dateType"], [role="combobox"][name*="dateType"]').first();
        
        if (await dateTypeSelect.isVisible()) {
            // Check that the select has the expected options
            const options = await dateTypeSelect.locator('option').allTextContents();
            
            // Verify that all expected date types are available
            expectedDateTypes.forEach(dateType => {
                expect(options.some(opt => opt.includes(dateType))).toBeTruthy();
            });
        }
    });
});
