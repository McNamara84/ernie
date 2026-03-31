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
            // Verify portal header branding is visible
            await expect(page.getByText('GFZ Data Services Portal')).toBeVisible();
        });

        test('displays filters sidebar', async ({ page }) => {
            await expect(page.getByText('Filters')).toBeVisible();
            await expect(page.getByText('Resource Type', { exact: true })).toBeVisible();
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
        test('displays resource type filter popover trigger', async ({ page }) => {
            await expect(page.getByRole('button', { name: 'All Resource Types' })).toBeVisible();
        });

        test('default selection shows All Resource Types', async ({ page }) => {
            const trigger = page.getByRole('button', { name: 'All Resource Types' });
            await expect(trigger).toBeVisible();
            await expect(trigger).toContainText('All Resource Types');
        });

        test('popover opens and shows search input', async ({ page }) => {
            await page.getByRole('button', { name: 'All Resource Types' }).click();
            await expect(page.getByPlaceholder('Search types...')).toBeVisible();
        });

        test('selecting a type updates URL with type parameter', async ({ page }) => {
            // Open the popover
            await page.getByRole('button', { name: 'All Resource Types' }).click();

            // Ensure at least one facet option is rendered
            const options = page.getByRole('option');
            await expect(options.first()).toBeVisible({ timeout: 5000 });

            // Click the first available type option
            await options.first().click();

            // URL should contain type[] parameter
            await page.waitForURL(/type/, { timeout: 10000 });
            expect(page.url()).toContain('type');
        });

        test('clearing selection removes type from URL', async ({ page }) => {
            // Navigate with a type filter pre-selected
            await page.goto('/portal?type[]=dataset');
            await page.waitForLoadState('networkidle');

            // The trigger must show "N selected" (not "All Resource Types")
            const trigger = page.getByRole('button').filter({ hasText: /selected/ });
            await expect(trigger).toBeVisible({ timeout: 5000 });

            // Open popover and press clear
            await trigger.click();
            const clearButton = page.getByRole('button', { name: /clear selection/i });
            await expect(clearButton).toBeVisible();
            await clearButton.click();

            // URL should no longer contain type parameter
            await expect(async () => {
                expect(page.url()).not.toContain('type');
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
            await page.goto('/portal?q=climate&type[]=dataset&page=1');

            const searchInput = page.getByPlaceholder(/search datasets/i);
            await expect(searchInput).toHaveValue('climate');

            // The type filter trigger should show selection count instead of "All Resource Types"
            const trigger = page.getByRole('button').filter({ hasText: /selected/ });
            await expect(trigger).toBeVisible();
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
            // Should have a level-1 heading for accessibility/SEO
            const h1 = page.getByRole('heading', { level: 1 });
            await expect(h1.first()).toBeVisible();
            await expect(h1.first()).toHaveText('GFZ Data Services Portal');
        });

        test('search input has associated label', async ({ page }) => {
            const searchLabel = page.locator('label').filter({ hasText: 'Search' });
            await expect(searchLabel).toBeVisible();
        });

        test('resource type filter is accessible via button', async ({ page }) => {
            const filterButton = page.getByRole('button', { name: 'All Resource Types' });
            await expect(filterButton).toBeVisible();
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
