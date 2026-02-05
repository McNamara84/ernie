import { expect, test } from '@playwright/test';

/**
 * Portal E2E Tests
 *
 * Tests for the public data portal page with search, filters, map and results.
 * The portal is publicly accessible (no login required).
 */

test.describe('Portal Page', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/portal');
    });

    test.describe('Page Loading', () => {
        test('portal page loads successfully', async ({ page }) => {
            // Verify page title/heading is visible
            await expect(page.getByRole('heading', { name: /data portal/i })).toBeVisible();
        });

        test('displays filters sidebar', async ({ page }) => {
            await expect(page.getByText('Filters')).toBeVisible();
            await expect(page.getByText('Resource Type')).toBeVisible();
        });

        test('displays map component', async ({ page }) => {
            // Map toggle button should be visible
            await expect(page.getByRole('button', { name: /map/i })).toBeVisible();
        });

        test('displays results area', async ({ page }) => {
            // Either results or empty state should be visible
            const hasResults = await page.getByTestId('portal-results-list').first().isVisible();
            const hasEmptyState = await page.getByText(/no results/i).isVisible();
            expect(hasResults || hasEmptyState).toBe(true);
        });
    });

    test.describe('Search Functionality', () => {
        test('search input is focusable and accepts text', async ({ page }) => {
            const searchInput = page.getByPlaceholder(/search datasets/i);
            await expect(searchInput).toBeVisible();

            await searchInput.fill('climate');
            await expect(searchInput).toHaveValue('climate');
        });

        test('search updates URL with query parameter', async ({ page }) => {
            const searchInput = page.getByPlaceholder(/search datasets/i);
            await searchInput.fill('test query');

            // Submit the search
            await page.getByRole('button', { name: 'Search', exact: true }).click();

            // Wait for URL to update
            await page.waitForURL(/q=test/);
            expect(page.url()).toContain('q=');
        });

        test('clear search button removes query', async ({ page }) => {
            const searchInput = page.getByPlaceholder(/search datasets/i);
            await searchInput.fill('something');
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await page.waitForURL(/q=/);

            // Clear the search using the X button
            await page.getByRole('button', { name: /clear search/i }).click();

            // Input should be cleared
            await expect(searchInput).toHaveValue('');
        });
    });

    test.describe('Type Filter', () => {
        test('displays all filter options', async ({ page }) => {
            await expect(page.getByLabel(/all resources/i)).toBeVisible();
            await expect(page.getByLabel(/doi resources/i)).toBeVisible();
            await expect(page.getByLabel(/igsn samples/i)).toBeVisible();
        });

        test('default selection is All Resources', async ({ page }) => {
            const allRadio = page.getByLabel(/all resources/i);
            await expect(allRadio).toBeChecked();
        });

        test('selecting DOI filter updates URL', async ({ page }) => {
            const doiRadio = page.getByLabel(/doi resources/i);
            await doiRadio.click();

            await page.waitForURL(/type=doi/);
            expect(page.url()).toContain('type=doi');
        });

        test('selecting IGSN filter updates URL', async ({ page }) => {
            const igsnRadio = page.getByLabel(/igsn samples/i);
            await igsnRadio.click();

            await page.waitForURL(/type=igsn/);
            expect(page.url()).toContain('type=igsn');
        });

        test('selecting All Resources removes type from URL', async ({ page }) => {
            // First select DOI
            const doiRadio = page.getByLabel(/doi resources/i);
            await doiRadio.click();
            await page.waitForURL(/type=doi/);

            // Then select All
            const allRadio = page.getByLabel(/all resources/i);
            await allRadio.click();

            // Type param should be removed or set to 'all'
            await expect(async () => {
                const url = page.url();
                // Either no type param or type=all
                expect(!url.includes('type=') || url.includes('type=all')).toBe(true);
            }).toPass({ timeout: 5000 });
        });
    });

    test.describe('Map Interaction', () => {
        test('map can be collapsed and expanded', async ({ page }) => {
            const mapToggle = page.getByRole('button', { name: /map/i });

            // Click to collapse
            await mapToggle.click();

            // Click to expand
            await mapToggle.click();

            // Map should be visible (use first() since there might be multiple)
            await expect(page.locator('.leaflet-container').first()).toBeVisible();
        });

        test('map shows OpenStreetMap attribution', async ({ page }) => {
            await expect(page.getByRole('link', { name: 'OpenStreetMap' }).first()).toBeVisible();
        });
    });

    test.describe('Filter Sidebar Toggle', () => {
        test('sidebar can be collapsed', async ({ page }) => {
            const collapseButton = page.getByRole('button', { name: /collapse filters/i });

            await collapseButton.click();

            // Search input should not be visible in collapsed state
            await expect(page.getByPlaceholder(/search datasets/i)).not.toBeVisible();
        });

        test('collapsed sidebar can be expanded', async ({ page }) => {
            // First collapse
            const collapseButton = page.getByRole('button', { name: /collapse filters/i });
            await collapseButton.click();

            // Then expand
            const expandButton = page.getByRole('button', { name: /expand filters/i });
            await expandButton.click();

            // Search input should be visible again
            await expect(page.getByPlaceholder(/search datasets/i)).toBeVisible();
        });
    });

    test.describe('URL State Persistence', () => {
        test('filters are restored from URL on page load', async ({ page }) => {
            // Navigate directly with query params
            await page.goto('/portal?q=climate&type=doi&page=1');

            const searchInput = page.getByPlaceholder(/search datasets/i);
            await expect(searchInput).toHaveValue('climate');

            const doiRadio = page.getByLabel(/doi resources/i);
            await expect(doiRadio).toBeChecked();
        });

        test('URL state survives page refresh', async ({ page }) => {
            // Set some filters
            const searchInput = page.getByPlaceholder(/search datasets/i);
            await searchInput.fill('test');
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await page.waitForURL(/q=test/);

            // Refresh page
            await page.reload();

            // Filters should be restored
            await expect(searchInput).toHaveValue('test');
        });
    });

    test.describe('Results Display', () => {
        test('results show resource cards or empty state', async ({ page }) => {
            // Wait for either results area or empty state to be visible
            const resultsArea = page.getByTestId('portal-results-list').first();
            const emptyState = page.getByText(/no results found/i);

            // One of these should be visible within 5 seconds
            await expect(async () => {
                const hasResults = await resultsArea.isVisible();
                const hasEmpty = await emptyState.isVisible();
                expect(hasResults || hasEmpty).toBe(true);
            }).toPass({ timeout: 5000 });
        });

        test('pagination appears when there are multiple pages', async ({ page }) => {
            // This test is conditional - only checks pagination if there are enough results
            const resultsText = page.getByText(/showing \d+-\d+ of \d+ results/i).first();

            if (await resultsText.isVisible()) {
                const text = await resultsText.textContent();
                const match = text?.match(/of (\d+) results/);
                if (match) {
                    const total = parseInt(match[1], 10);
                    if (total > 20) {
                        // Should have pagination
                        await expect(page.getByRole('button', { name: /next/i })).toBeVisible();
                    }
                }
            }
        });
    });

    test.describe('Accessibility', () => {
        test('page has proper heading structure', async ({ page }) => {
            // Should have main heading
            const h1 = page.getByRole('heading', { level: 1 });
            await expect(h1.first()).toBeVisible();
        });

        test('search input has associated label', async ({ page }) => {
            const searchLabel = page.locator('label').filter({ hasText: 'Search' });
            await expect(searchLabel).toBeVisible();
        });

        test('radio buttons are properly grouped', async ({ page }) => {
            const radioGroup = page.getByRole('radiogroup');
            await expect(radioGroup).toBeVisible();
        });

        test('interactive elements are keyboard accessible', async ({ page }) => {
            // Focus the search input directly
            const searchInput = page.getByPlaceholder(/search datasets/i);
            await searchInput.focus();
            
            // Should be able to type
            await page.keyboard.type('keyboard test');

            await expect(searchInput).toHaveValue('keyboard test');
        });
    });
});
