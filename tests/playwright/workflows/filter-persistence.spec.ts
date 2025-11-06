import { expect, test } from '@playwright/test';

/**
 * E2E tests for filter persistence across page loads and navigation.
 * 
 * This test suite ensures that filters applied via URL parameters are:
 * 1. Correctly parsed and displayed in the UI
 * 2. Reflected in filter badges
 * 3. Shown in dropdown selections
 * 4. Persist across page reloads
 * 
 * Bug context: Previously, filters were applied to data but not to UI state,
 * causing disconnected UX where filtered results showed but no active filters
 * appeared in the interface.
 */

test.describe('Resources Page - Filter Persistence', () => {
    test.beforeEach(async ({ page }) => {
        // Login as admin to access resources page
        await page.goto('/login');
        await page.fill('input[name="email"]', 'admin@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('/dashboard');
    });

    test('should initialize resource_type filter from URL parameter', async ({ page }) => {
        // Navigate directly with resource_type filter in URL
        await page.goto('/resources?resource_type[]=dataset');
        
        // Wait for page to load
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check that the filter badge is visible
        const filterBadge = page.locator('text=/Type:.*Dataset/i');
        await expect(filterBadge).toBeVisible();
        
        // Check that the select shows the correct value
        const resourceTypeSelect = page.getByRole('combobox', { name: /filter by resource type/i });
        await expect(resourceTypeSelect).toContainText('Dataset');
        
        // Verify that results are actually filtered
        const resultCount = page.locator('text=/Showing.*of.*resources/i');
        await expect(resultCount).toBeVisible();
    });

    test('should initialize status filter from URL parameter', async ({ page }) => {
        await page.goto('/resources?status[]=published');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check filter badge
        const filterBadge = page.locator('text=/Status:.*Published/i');
        await expect(filterBadge).toBeVisible();
        
        // Check select value
        const statusSelect = page.getByRole('combobox', { name: /filter by publication status/i });
        await expect(statusSelect).toContainText('Published');
    });

    test('should initialize curator filter from URL parameter', async ({ page }) => {
        await page.goto('/resources?curator[]=Admin+User');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check filter badge
        const filterBadge = page.locator('text=/Curator:.*Admin User/i');
        await expect(filterBadge).toBeVisible();
        
        // Check select value
        const curatorSelect = page.getByRole('combobox', { name: /filter by curator/i });
        await expect(curatorSelect).toContainText('Admin User');
    });

    test('should initialize year range filters from URL parameters', async ({ page }) => {
        await page.goto('/resources?year_from=2020&year_to=2023');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check year range badges
        const yearFromBadge = page.locator('text=/Year from:.*2020/i');
        await expect(yearFromBadge).toBeVisible();
        
        const yearToBadge = page.locator('text=/Year to:.*2023/i');
        await expect(yearToBadge).toBeVisible();
        
        // Check that the year range button shows the range
        const yearRangeButton = page.locator('button:has-text("2020 - 2023")');
        await expect(yearRangeButton).toBeVisible();
    });

    test('should initialize search filter from URL parameter', async ({ page }) => {
        const searchTerm = 'climate';
        await page.goto(`/resources?search=${searchTerm}`);
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check search badge
        const searchBadge = page.locator(`text=/Search:.*${searchTerm}/i`);
        await expect(searchBadge).toBeVisible();
        
        // Check search input value
        const searchInput = page.getByRole('searchbox', { name: /search resources/i });
        await expect(searchInput).toHaveValue(searchTerm);
    });

    test('should initialize multiple filters from URL parameters', async ({ page }) => {
        await page.goto('/resources?resource_type[]=dataset&status[]=published&year_from=2020');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check all filter badges
        await expect(page.locator('text=/Type:.*Dataset/i')).toBeVisible();
        await expect(page.locator('text=/Status:.*Published/i')).toBeVisible();
        await expect(page.locator('text=/Year from:.*2020/i')).toBeVisible();
        
        // Verify active filters count
        const activeFiltersLabel = page.locator('text=/Active filters:/i');
        await expect(activeFiltersLabel).toBeVisible();
    });

    test('should persist filters after page reload', async ({ page }) => {
        // Apply filter via UI
        await page.goto('/resources');
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Select a resource type
        const resourceTypeSelect = page.getByRole('combobox', { name: /filter by resource type/i });
        await resourceTypeSelect.click();
        await page.getByRole('option', { name: 'Dataset' }).click();
        
        // Wait for URL to update
        await page.waitForURL(/resource_type/);
        
        // Reload the page
        await page.reload();
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Verify filter is still active
        await expect(page.locator('text=/Type:.*Dataset/i')).toBeVisible();
        await expect(resourceTypeSelect).toContainText('Dataset');
    });

    test('should allow removing individual filters via badges', async ({ page }) => {
        await page.goto('/resources?resource_type[]=dataset&status[]=published');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Find and click the remove button on the resource_type filter badge
        const typeBadge = page.locator('text=/Type:.*Dataset/i').locator('..');
        const removeButton = typeBadge.locator('button[aria-label*="Remove"]');
        await removeButton.click();
        
        // Wait for URL to update
        await page.waitForURL((url) => !url.search.includes('resource_type'));
        
        // Verify the badge is gone
        await expect(page.locator('text=/Type:.*Dataset/i')).not.toBeVisible();
        
        // Verify the other filter is still active
        await expect(page.locator('text=/Status:.*Published/i')).toBeVisible();
    });

    test('should clear all filters when clicking "Clear All" button', async ({ page }) => {
        await page.goto('/resources?resource_type[]=dataset&status[]=published&year_from=2020');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Click "Clear All" button
        const clearAllButton = page.getByRole('button', { name: /clear all/i });
        await clearAllButton.click();
        
        // Wait for URL to update
        await page.waitForURL('/resources');
        
        // Verify all filter badges are gone
        await expect(page.locator('text=/Type:.*Dataset/i')).not.toBeVisible();
        await expect(page.locator('text=/Status:.*Published/i')).not.toBeVisible();
        await expect(page.locator('text=/Year from:.*2020/i')).not.toBeVisible();
        
        // Verify "Active filters" label is not shown
        await expect(page.locator('text=/Active filters:/i')).not.toBeVisible();
    });

    test('should initialize date range filters from URL parameters', async ({ page }) => {
        await page.goto('/resources?created_from=2023-01-01&created_to=2023-12-31');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Check filter badges
        const createdFromBadge = page.locator('text=/Created from:.*2023-01-01/i');
        await expect(createdFromBadge).toBeVisible();
        
        const createdToBadge = page.locator('text=/Created to:.*2023-12-31/i');
        await expect(createdToBadge).toBeVisible();
        
        // Open the created date popover to verify input values
        const createdDateButton = page.locator('button:has-text("Created:")');
        await createdDateButton.click();
        
        // Check input values in the popover
        const fromInput = page.locator('input#created-from');
        await expect(fromInput).toHaveValue('2023-01-01');
        
        const toInput = page.locator('input#created-to');
        await expect(toInput).toHaveValue('2023-12-31');
    });
});

test.describe('Old Datasets Page - Filter Persistence', () => {
    test.beforeEach(async ({ page }) => {
        // Login as admin to access old datasets page
        await page.goto('/login');
        await page.fill('input[name="email"]', 'admin@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('/dashboard');
    });

    test('should initialize resource_type filter from URL parameter on old-datasets page', async ({ page }) => {
        await page.goto('/old-datasets?resource_type[]=dataset');
        
        // Wait for page to load
        await page.waitForSelector('h1:has-text("Old Datasets")');
        
        // Check that the filter badge is visible
        const filterBadge = page.locator('text=/Type:.*dataset/i');
        await expect(filterBadge).toBeVisible();
        
        // Check that the select shows the correct value
        const resourceTypeSelect = page.getByRole('combobox', { name: /filter by resource type/i });
        await expect(resourceTypeSelect).toContainText('dataset');
    });

    test('should initialize multiple filters from URL parameters on old-datasets page', async ({ page }) => {
        await page.goto('/old-datasets?resource_type[]=dataset&status[]=published');
        
        await page.waitForSelector('h1:has-text("Old Datasets")');
        
        // Check all filter badges
        await expect(page.locator('text=/Type:.*dataset/i')).toBeVisible();
        await expect(page.locator('text=/Status:.*Published/i')).toBeVisible();
        
        // Verify active filters label
        const activeFiltersLabel = page.locator('text=/Active filters:/i');
        await expect(activeFiltersLabel).toBeVisible();
    });

    test('should persist filters after applying via UI on old-datasets page', async ({ page }) => {
        await page.goto('/old-datasets');
        await page.waitForSelector('h1:has-text("Old Datasets")');
        
        // Select a status filter
        const statusSelect = page.getByRole('combobox', { name: /filter by publication status/i });
        await statusSelect.click();
        await page.getByRole('option', { name: 'Published' }).click();
        
        // Wait for URL to update
        await page.waitForURL(/status/);
        
        // Get current URL
        const currentUrl = page.url();
        
        // Navigate away and back
        await page.goto('/');
        await page.goto(currentUrl);
        
        await page.waitForSelector('h1:has-text("Old Datasets")');
        
        // Verify filter is still active
        await expect(page.locator('text=/Status:.*Published/i')).toBeVisible();
        await expect(statusSelect).toContainText('Published');
    });
});

test.describe('Filter Persistence - Edge Cases', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', 'admin@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        await page.waitForURL('/dashboard');
    });

    test('should handle URL-encoded filter values correctly', async ({ page }) => {
        // Use URL-encoded curator name
        await page.goto('/resources?curator[]=Admin%20User');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Should decode and display correctly
        const filterBadge = page.locator('text=/Curator:.*Admin User/i');
        await expect(filterBadge).toBeVisible();
    });

    test('should handle invalid year values gracefully', async ({ page }) => {
        // Navigate with invalid year
        await page.goto('/resources?year_from=invalid');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Should not show any year filter badge
        await expect(page.locator('text=/Year from:/i')).not.toBeVisible();
        
        // Page should still load normally
        await expect(page.locator('h1:has-text("Resources")')).toBeVisible();
    });

    test('should handle negative year values gracefully', async ({ page }) => {
        await page.goto('/resources?year_from=-2020');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Should not show year filter badge for negative year
        await expect(page.locator('text=/Year from:/i')).not.toBeVisible();
    });

    test('should handle empty filter values gracefully', async ({ page }) => {
        await page.goto('/resources?search=&year_from=');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Should not show any filter badges for empty values
        await expect(page.locator('text=/Search:/i')).not.toBeVisible();
        await expect(page.locator('text=/Year from:/i')).not.toBeVisible();
    });

    test('should maintain filter state during sort changes', async ({ page }) => {
        await page.goto('/resources?resource_type[]=dataset');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Verify initial filter
        await expect(page.locator('text=/Type:.*Dataset/i')).toBeVisible();
        
        // Change sort order (click on a sortable column header)
        const titleSortButton = page.locator('button:has-text("Title")').first();
        await titleSortButton.click();
        
        // Wait for URL to update with sort parameters
        await page.waitForURL(/sort_key/);
        
        // Verify filter is still active after sort change
        await expect(page.locator('text=/Type:.*Dataset/i')).toBeVisible();
        
        // Verify URL contains both filter and sort parameters
        const url = page.url();
        expect(url).toContain('resource_type');
        expect(url).toContain('sort_key');
    });

    test('should handle multiple values for same filter type', async ({ page }) => {
        // Note: Current implementation uses single select, but this tests robustness
        await page.goto('/resources?resource_type[]=dataset&resource_type[]=collection');
        
        await page.waitForSelector('h1:has-text("Resources")');
        
        // Should show at least one of the values (implementation picks first)
        const resourceTypeSelect = page.getByRole('combobox', { name: /filter by resource type/i });
        const selectText = await resourceTypeSelect.textContent();
        
        // Should show either dataset or collection (implementation detail)
        expect(selectText?.toLowerCase()).toMatch(/(dataset|collection)/);
    });
});
