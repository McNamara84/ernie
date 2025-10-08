import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests for loading dates with mocked API responses.
 * These tests verify the three DataCite date format types work correctly:
 * - Single date: startDate filled, endDate empty
 * - Full range: both startDate and endDate filled
 * - Open range: startDate empty, endDate filled
 * 
 * Based on actual data from old database Dataset ID 3.
 */

test.describe('Load dates from old datasets (mocked)', () => {
    test.beforeEach(async ({ page }) => {
        // Login
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
    });

    test('loads all three date format types correctly', async ({ page }) => {
        // Mock the dates API endpoint to return test data
        await page.route('**/old-datasets/*/dates', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    dates: [
                        {
                            dateType: 'available',
                            startDate: '',
                            endDate: '2017-03-01',
                        },
                        {
                            dateType: 'created',
                            startDate: '2015-03-10',
                            endDate: '',
                        },
                        {
                            dateType: 'collected',
                            startDate: '2013-09-05',
                            endDate: '2014-10-11',
                        },
                    ],
                }),
            });
        });

        // Mock other required endpoints
        await page.route('**/old-datasets/*/authors', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ authors: [] }),
            });
        });

        await page.route('**/old-datasets/*/contributors', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ contributors: [] }),
            });
        });

        await page.route('**/old-datasets/*/descriptions', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ descriptions: [] }),
            });
        });

        // Navigate directly to curation with mocked dataset ID
        await page.goto('/curation?doi=test&year=2024&id=3');
        await expect(page).toHaveURL(/\/curation/);

        // Wait for form to load
        await expect(page.getByRole('heading', { name: 'Create Resource' })).toBeVisible();

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        await datesTrigger.click();
        await expect(datesTrigger).toHaveAttribute('aria-expanded', 'true');

        // Verify all three date types are loaded
        
        // 1. Check Available date (open range: /2017-03-01)
        const availableDateTypeSelect = page.locator('select').filter({ hasText: /Available/i }).first();
        await expect(availableDateTypeSelect).toBeVisible();
        
        // Find the associated Start Date and End Date inputs
        // Available should have empty start, "2017-03-01" in end
        const availableRow = page.locator('div').filter({ has: availableDateTypeSelect });
        const availableStartDate = availableRow.getByLabel(/Start Date/i);
        const availableEndDate = availableRow.getByLabel(/End Date/i);
        
        await expect(availableStartDate).toHaveValue('');
        await expect(availableEndDate).toHaveValue('2017-03-01');

        // 2. Check Created date (single date: 2015-03-10)
        const createdDateTypeSelect = page.locator('select').filter({ hasText: /Created/i }).first();
        await expect(createdDateTypeSelect).toBeVisible();
        
        const createdRow = page.locator('div').filter({ has: createdDateTypeSelect });
        const createdStartDate = createdRow.getByLabel(/Start Date/i);
        const createdEndDate = createdRow.getByLabel(/End Date/i);
        
        await expect(createdStartDate).toHaveValue('2015-03-10');
        await expect(createdEndDate).toHaveValue('');

        // 3. Check Collected date (full range: 2013-09-05/2014-10-11)
        const collectedDateTypeSelect = page.locator('select').filter({ hasText: /Collected/i }).first();
        await expect(collectedDateTypeSelect).toBeVisible();
        
        const collectedRow = page.locator('div').filter({ has: collectedDateTypeSelect });
        const collectedStartDate = collectedRow.getByLabel(/Start Date/i);
        const collectedEndDate = collectedRow.getByLabel(/End Date/i);
        
        await expect(collectedStartDate).toHaveValue('2013-09-05');
        await expect(collectedEndDate).toHaveValue('2014-10-11');
    });

    test('loads single date format correctly', async ({ page }) => {
        // Mock API with only Created date (single date format)
        await page.route('**/old-datasets/*/dates', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    dates: [
                        {
                            dateType: 'created',
                            startDate: '2015-03-10',
                            endDate: '',
                        },
                    ],
                }),
            });
        });

        // Mock other endpoints
        await page.route('**/old-datasets/*/authors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ authors: [] }) });
        });
        await page.route('**/old-datasets/*/contributors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ contributors: [] }) });
        });
        await page.route('**/old-datasets/*/descriptions', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ descriptions: [] }) });
        });

        await page.goto('/curation?doi=test&year=2024&id=1');

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        await datesTrigger.click();

        // Verify Created date with single date format
        const createdStartDate = page.getByLabel(/Start Date/i).first();
        const createdEndDate = page.getByLabel(/End Date/i).first();
        
        await expect(createdStartDate).toHaveValue('2015-03-10');
        await expect(createdEndDate).toHaveValue('');
    });

    test('loads full range date format correctly', async ({ page }) => {
        // Mock API with Collected date (full range format)
        await page.route('**/old-datasets/*/dates', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    dates: [
                        {
                            dateType: 'collected',
                            startDate: '2013-09-05',
                            endDate: '2014-10-11',
                        },
                    ],
                }),
            });
        });

        // Mock other endpoints
        await page.route('**/old-datasets/*/authors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ authors: [] }) });
        });
        await page.route('**/old-datasets/*/contributors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ contributors: [] }) });
        });
        await page.route('**/old-datasets/*/descriptions', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ descriptions: [] }) });
        });

        await page.goto('/curation?doi=test&year=2024&id=2');

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        await datesTrigger.click();

        // Verify Collected date with full range format
        const collectedStartDate = page.getByLabel(/Start Date/i).first();
        const collectedEndDate = page.getByLabel(/End Date/i).first();
        
        await expect(collectedStartDate).toHaveValue('2013-09-05');
        await expect(collectedEndDate).toHaveValue('2014-10-11');
    });

    test('loads open-ended range date format correctly', async ({ page }) => {
        // Mock API with Available date (open-ended range format)
        await page.route('**/old-datasets/*/dates', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    dates: [
                        {
                            dateType: 'available',
                            startDate: '',
                            endDate: '2017-03-01',
                        },
                    ],
                }),
            });
        });

        // Mock other endpoints
        await page.route('**/old-datasets/*/authors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ authors: [] }) });
        });
        await page.route('**/old-datasets/*/contributors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ contributors: [] }) });
        });
        await page.route('**/old-datasets/*/descriptions', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ descriptions: [] }) });
        });

        await page.goto('/curation?doi=test&year=2024&id=3');

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        await datesTrigger.click();

        // Verify Available date with open-ended range format
        const availableStartDate = page.getByLabel(/Start Date/i).first();
        const availableEndDate = page.getByLabel(/End Date/i).first();
        
        await expect(availableStartDate).toHaveValue('');
        await expect(availableEndDate).toHaveValue('2017-03-01');
    });

    test('handles empty dates array gracefully', async ({ page }) => {
        // Mock API with no dates
        await page.route('**/old-datasets/*/dates', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ dates: [] }),
            });
        });

        // Mock other endpoints
        await page.route('**/old-datasets/*/authors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ authors: [] }) });
        });
        await page.route('**/old-datasets/*/contributors', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ contributors: [] }) });
        });
        await page.route('**/old-datasets/*/descriptions', async (route) => {
            await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ descriptions: [] }) });
        });

        await page.goto('/curation?doi=test&year=2024&id=100');

        // Open Dates Accordion
        const datesTrigger = page.getByRole('button', { name: 'Dates' });
        await datesTrigger.click();

        // Form should still work, with default Created date field
        const createdDateField = page.getByLabel(/Start Date/i).first();
        await expect(createdDateField).toBeVisible();
        await expect(createdDateField).toBeEnabled();
    });
});
