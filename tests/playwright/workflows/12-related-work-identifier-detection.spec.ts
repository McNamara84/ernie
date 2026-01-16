import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * E2E Tests for Related Work Identifier Type Auto-Detection
 *
 * These tests verify that the Related Work form correctly auto-detects
 * identifier types when users enter identifiers. The detection should
 * support various DOI formats, URLs, Handles, and other identifier types.
 *
 * The tests use real-world examples of identifiers to ensure accurate detection.
 *
 * Test Strategy:
 * 1. Enter an identifier in the input field
 * 2. Click the Add button to add the related work
 * 3. Check the identifier type badge in the added item list
 */

test.describe('Related Work Identifier Type Detection', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await page.goto('/editor');
        await page.waitForSelector('[data-slot="accordion-trigger"]');

        // Expand Related Work accordion
        const relatedWorkAccordion = page.locator('[data-slot="accordion-trigger"]', { hasText: /Related Work/i });
        await relatedWorkAccordion.click();
        await page.waitForTimeout(300); // Wait for accordion animation
    });

    /**
     * Helper function to add a related work and verify its identifier type
     */
    async function addRelatedWorkAndVerifyType(
        page: import('@playwright/test').Page,
        identifier: string,
        expectedType: string,
    ) {
        // Enter the identifier
        const identifierInput = page.locator('#related-identifier');
        await identifierInput.fill(identifier);

        // Wait for validation to complete (debounced)
        await page.waitForTimeout(1000);

        // Click the Add button
        const addButton = page.locator('button[aria-label="Add related work"]');
        await addButton.click();

        // Wait for the item to be added
        await page.waitForTimeout(300);

        // Find the last added item's identifier type badge
        // The badge shows the identifier type in the item list
        const items = page.locator('[role="listitem"]');
        const lastItem = items.last();
        const typeBadge = lastItem.locator('.text-xs', { hasText: expectedType });

        await expect(typeBadge).toBeVisible();
    }

    test.describe('DOI Detection', () => {
        /**
         * Test cases for DOI auto-detection.
         * Each test enters a DOI in various formats and verifies the identifier type is set to "DOI"
         */

        test('detects bare DOI format: 10.5880/fidgeo.2025.072', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/fidgeo.2025.072', 'DOI');
        });

        test('detects DOI with URL prefix: https://doi.org/10.5880/fidgeo.2026.001', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.5880/fidgeo.2026.001', 'DOI');
        });

        test('detects DIGIS DOI: 10.5880/digis.2025.005', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/digis.2025.005', 'DOI');
        });

        test('detects AGU publication DOI: https://doi.org/10.1029/2015EO022207', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://doi.org/10.1029/2015EO022207', 'DOI');
        });

        test('detects GFZ DOI with uppercase: 10.5880/GFZ.DMJQ.2025.005', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5880/GFZ.DMJQ.2025.005', 'DOI');
        });

        test('detects EGU abstract DOI: 10.5194/egusphere-egu25-20132', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.5194/egusphere-egu25-20132', 'DOI');
        });

        test('detects DOI with doi: prefix: doi:10.1371/journal.pbio.0020449', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'doi:10.1371/journal.pbio.0020449', 'DOI');
        });

        test('detects PLOS Biology DOI: 10.1371/journal.pbio.0020449', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '10.1371/journal.pbio.0020449', 'DOI');
        });

        test('detects legacy dx.doi.org URL: https://dx.doi.org/10.5880/fidgeo.2025.072', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://dx.doi.org/10.5880/fidgeo.2025.072', 'DOI');
        });

        test('detects DOI with http:// prefix: http://doi.org/10.5880/test.2025.001', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'http://doi.org/10.5880/test.2025.001', 'DOI');
        });

        test.describe('DOI edge cases', () => {
            test('handles DOI with parentheses: 10.1000/xyz(2023)001', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.1000/xyz(2023)001', 'DOI');
            });

            test('handles DOI with underscores: 10.5880/fidgeo_special_2025', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.5880/fidgeo_special_2025', 'DOI');
            });

            test('handles DOI with 5-digit registrant code: 10.12345/example.2025', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '10.12345/example.2025', 'DOI');
            });

            test('handles DOI with leading/trailing whitespace', async ({ page }) => {
                await addRelatedWorkAndVerifyType(page, '  10.5880/fidgeo.2025.072  ', 'DOI');
            });
        });
    });

    test.describe('Non-DOI identifiers should not be detected as DOI', () => {
        test('detects plain URL as URL, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://example.com/resource', 'URL');
        });

        test('detects Handle URL as Handle, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, 'https://hdl.handle.net/11234/56789', 'Handle');
        });

        test('detects bare Handle as Handle, not DOI', async ({ page }) => {
            await addRelatedWorkAndVerifyType(page, '11234/56789', 'Handle');
        });
    });
});
