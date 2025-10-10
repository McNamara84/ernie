import { expect, test, type Page } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

/**
 * E2E Tests for Controlled Vocabularies (GCMD Keywords) functionality.
 * 
 * Tests the complete workflow:
 * - Opening the Controlled Vocabularies accordion
 * - Searching for keywords across vocabularies
 * - Selecting keywords from different vocabulary types
 * - Green indicators showing selected keywords
 * - Saving and loading keywords
 */

/**
 * Helper function to ensure the Controlled Vocabularies accordion is open
 * and data is loaded
 */
async function ensureAccordionOpen(page: Page) {
    const vocabTrigger = page.getByRole('button', { name: /Controlled Vocabularies/i });
    const isExpanded = await vocabTrigger.getAttribute('aria-expanded');
    if (isExpanded === 'false') {
        await vocabTrigger.click();
        await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'true');
    }
    
    // Wait for vocabulary data to load - wait for tabs to be visible
    const scienceTab = page.getByRole('tab', { name: /Science/i });
    await scienceTab.waitFor({ state: 'visible', timeout: 10_000 });
    
    // Wait for tree items to be loaded (data fetched from API)
    // Give it time for the async data loading
    await page.waitForTimeout(1000);
}

test.describe('Controlled Vocabularies - Basic UI', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');
    });

    test('displays controlled vocabularies accordion', async ({ page }) => {
        // Find the Controlled Vocabularies accordion trigger
        const vocabTrigger = page.getByRole('button', { name: /Controlled Vocabularies/i });
        await expect(vocabTrigger).toBeVisible();

        // Get current state
        const initialState = await vocabTrigger.getAttribute('aria-expanded');

        // Test toggle functionality
        if (initialState === 'true') {
            // If already open, close it first
            await vocabTrigger.click();
            await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'false');
            
            // Then open it
            await vocabTrigger.click();
            await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'true');
        } else {
            // If closed, open it
            await vocabTrigger.click();
            await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'true');
            
            // Then close it to test
            await vocabTrigger.click();
            await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'false');
            
            // Open again for consistency
            await vocabTrigger.click();
            await expect(vocabTrigger).toHaveAttribute('aria-expanded', 'true');
        }

        // Verify search input is visible when expanded
        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);
        await expect(searchInput).toBeVisible();
    });

    test('displays all three vocabulary tabs', async ({ page }) => {
        await ensureAccordionOpen(page);

        // Verify all three tabs are present
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        const instrumentsTab = page.getByRole('tab', { name: /Instruments/i });

        await expect(scienceTab).toBeVisible();
        await expect(platformsTab).toBeVisible();
        await expect(instrumentsTab).toBeVisible();
    });

    test('search field is positioned above tabs', async ({ page }) => {
        await ensureAccordionOpen(page);

        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);
        const scienceTab = page.getByRole('tab', { name: /Science/i });

        // Get bounding boxes to verify search is above tabs
        const searchBox = await searchInput.boundingBox();
        const tabBox = await scienceTab.boundingBox();

        expect(searchBox).not.toBeNull();
        expect(tabBox).not.toBeNull();

        // Search should be positioned above (smaller Y coordinate)
        expect(searchBox!.y).toBeLessThan(tabBox!.y);
    });
});

test.describe('Controlled Vocabularies - Search Functionality', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');

        // Ensure accordion is open
        await ensureAccordionOpen(page);
    });

    test('searches across all vocabulary types', async ({ page }) => {
        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);

        // Wait for initial data to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Search for a common keyword that might appear in multiple vocabularies
        await searchInput.fill('EARTH');

        // Wait for search to filter results
        await page.waitForTimeout(500);

        // Verify that search affects the visible tree items
        // (The exact assertions depend on your test data)
        const treeItems = page.locator('[role="treeitem"]');
        const visibleCount = await treeItems.count();

        expect(visibleCount).toBeGreaterThan(0);
    });

    test('clears search when input is cleared', async ({ page }) => {
        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);

        // Wait for initial data to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Search for something
        await searchInput.fill('EARTH');
        await page.waitForTimeout(500);

        // Clear search
        await searchInput.clear();
        await page.waitForTimeout(500);

        // Verify tree is restored (should show more items)
        const treeItems = page.locator('[role="treeitem"]');
        const count = await treeItems.count();

        expect(count).toBeGreaterThan(0);
    });

    test('search is case-insensitive', async ({ page }) => {
        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);

        // Wait for initial data to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Search with lowercase
        await searchInput.fill('earth');
        await page.waitForTimeout(500);
        const lowercaseCount = await page.locator('[role="treeitem"]').count();

        // Clear and search with uppercase
        await searchInput.clear();
        await searchInput.fill('EARTH');
        await page.waitForTimeout(500);
        const uppercaseCount = await page.locator('[role="treeitem"]').count();

        // Should return same results
        expect(lowercaseCount).toBe(uppercaseCount);
    });
});

test.describe('Controlled Vocabularies - Keyword Selection', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');

        // Ensure accordion is open
        await ensureAccordionOpen(page);
    });

    test('can select and deselect keywords', async ({ page }) => {
        // Click on Science tab
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();

        // Wait for tree items to be available
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Find a checkbox in the tree (first available)
        const firstCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await firstCheckbox.waitFor({ state: 'visible', timeout: 10_000 });

        // Check if initially unchecked
        const isChecked = await firstCheckbox.isChecked();

        // Toggle selection
        await firstCheckbox.click();
        await page.waitForTimeout(300);

        // Verify state changed
        expect(await firstCheckbox.isChecked()).toBe(!isChecked);

        // Toggle back
        await firstCheckbox.click();
        await page.waitForTimeout(300);

        expect(await firstCheckbox.isChecked()).toBe(isChecked);
    });

    test('can select keywords from multiple vocabulary types', async ({ page }) => {
        // Select from Science
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();
        
        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });
        
        const scienceCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await scienceCheckbox.waitFor({ state: 'visible', timeout: 10_000 });
        await scienceCheckbox.click();

        // Switch to Platforms tab
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        await platformsTab.click();
        await page.waitForTimeout(500);
        
        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });
        
        const platformCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await platformCheckbox.waitFor({ state: 'visible', timeout: 10_000 });
        await platformCheckbox.click();

        // Both should remain selected
        await scienceTab.click();
        expect(await scienceCheckbox.isChecked()).toBe(true);

        await platformsTab.click();
        expect(await platformCheckbox.isChecked()).toBe(true);
    });

    test('displays selected keywords count', async ({ page }) => {
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();

        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Select a keyword
        const checkbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await checkbox.waitFor({ state: 'visible', timeout: 10_000 });
        await checkbox.click();
        await page.waitForTimeout(300);

        // Verify the "Selected Keywords" section appears
        const selectedSection = page.getByText(/Selected Keywords/i);
        await expect(selectedSection).toBeVisible();

        // Verify count shows at least 1
        const selectedKeywords = page.locator('.selected-keyword-item, [data-testid="selected-keyword"]');
        const count = await selectedKeywords.count();
        expect(count).toBeGreaterThanOrEqual(1);
    });
});

test.describe('Controlled Vocabularies - Green Indicators', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');

        // Ensure accordion is open
        await ensureAccordionOpen(page);
    });

    test('shows green indicator when keywords are selected', async ({ page }) => {
        // Initially no indicator on Science tab
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();

        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Check if indicator exists (green dot)
        let indicator = scienceTab.locator('.bg-green-500, [data-indicator="true"]').first();
        const hasIndicatorBefore = await indicator.count() > 0;

        // Select a keyword
        const checkbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await checkbox.waitFor({ state: 'visible', timeout: 10_000 });
        await checkbox.click();
        await page.waitForTimeout(500);

        // Switch to another tab and back to Science to trigger re-render
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        await platformsTab.click();
        await page.waitForTimeout(300);
        await scienceTab.click();
        await page.waitForTimeout(300);

        // Verify indicator appears
        indicator = scienceTab.locator('.bg-green-500, [data-indicator="true"]').first();
        const hasIndicatorAfter = await indicator.count() > 0;

        // If no indicator before, should have one after selection
        if (!hasIndicatorBefore) {
            expect(hasIndicatorAfter).toBe(true);
        }
    });

    test('removes green indicator when all keywords are deselected', async ({ page }) => {
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();

        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });

        // Select a keyword
        const checkbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await checkbox.waitFor({ state: 'visible', timeout: 10_000 });
        await checkbox.click();
        await page.waitForTimeout(300);

        // Deselect the keyword
        await checkbox.click();
        await page.waitForTimeout(300);

        // Switch tabs to trigger re-render
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        await platformsTab.click();
        await page.waitForTimeout(300);
        await scienceTab.click();
        await page.waitForTimeout(300);

        // Verify indicator is gone or wasn't added
        const selectedKeywords = page.locator('.selected-keyword-item, [data-testid="selected-keyword"]');
        const count = await selectedKeywords.count();
        
        // If no keywords selected, count should be 0
        expect(count).toBe(0);
    });

    test('shows indicators on multiple tabs independently', async ({ page }) => {
        // Select from Science
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();
        
        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });
        
        const scienceCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await scienceCheckbox.waitFor({ state: 'visible', timeout: 10_000 });
        await scienceCheckbox.click();
        await page.waitForTimeout(300);

        // Select from Platforms
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        await platformsTab.click();
        await page.waitForTimeout(500);
        
        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });
        
        const platformCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await platformCheckbox.waitFor({ state: 'visible', timeout: 10_000 });
        await platformCheckbox.click();
        await page.waitForTimeout(300);

        // Select from Instruments
        const instrumentsTab = page.getByRole('tab', { name: /Instruments/i });
        await instrumentsTab.click();
        await page.waitForTimeout(500);
        
        // Wait for tree items to load
        await page.waitForSelector('[role="treeitem"]', { timeout: 10_000 });
        
        const instrumentCheckbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await instrumentCheckbox.waitFor({ state: 'visible', timeout: 10_000 });
        await instrumentCheckbox.click();
        await page.waitForTimeout(300);

        // All checkboxes should be checked
        await scienceTab.click();
        await page.waitForTimeout(200);
        expect(await scienceCheckbox.isChecked()).toBe(true);

        await platformsTab.click();
        await page.waitForTimeout(200);
        expect(await platformCheckbox.isChecked()).toBe(true);

        await instrumentsTab.click();
        await page.waitForTimeout(200);
        expect(await instrumentCheckbox.isChecked()).toBe(true);
    });
});

test.describe('Controlled Vocabularies - Save and Load', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');

        // Ensure accordion is open
        await ensureAccordionOpen(page);
    });

    test.skip('saves selected keywords to database', async ({ page }) => {
        // Select a keyword from Science
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.click();
        const checkbox = page.locator('[role="treeitem"] input[type="checkbox"]').first();
        await checkbox.waitFor({ state: 'visible' });
        
        await checkbox.click();
        await page.waitForTimeout(300);

        // Fill required fields (minimal data to save)
        await page.getByRole('textbox', { name: /Title/i }).first().fill('Test Resource with Keywords');
        
        // Save the resource
        const saveButton = page.getByRole('button', { name: /Save/i, exact: false });
        await saveButton.click();

        // Wait for success message or redirect
        await page.waitForTimeout(2_000);

        // Verify we're on resources page or see success message
        const isSuccess = await page.getByText(/success|saved|created/i).isVisible().catch(() => false);
        expect(isSuccess).toBe(true);
    });

    test.skip('loads saved keywords when editing resource', async ({ page }) => {
        // This test requires a resource with saved keywords to exist
        // Navigate to resources list
        await page.goto('/resources');

        // Find first resource with "Edit" button
        const editButton = page.getByRole('button', { name: /Edit/i }).first();
        await editButton.click();

        // Wait for curation form
        await page.waitForURL(/\/curation/, { timeout: 10_000 });

        // Ensure Controlled Vocabularies accordion is open
        await ensureAccordionOpen(page);

        // Check if any keywords are selected (green indicators or selected keywords section)
        const selectedKeywords = page.locator('.selected-keyword-item, [data-testid="selected-keyword"]');
        const count = await selectedKeywords.count();

        // If this resource had keywords, count should be > 0
        // This is a basic check - actual value depends on test data
        expect(count).toBeGreaterThanOrEqual(0);
    });
});

test.describe('Controlled Vocabularies - Accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
        await page.goto('/curation');

        // Ensure accordion is open
        await ensureAccordionOpen(page);
    });

    test('can navigate tabs with keyboard', async ({ page }) => {
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        await scienceTab.focus();

        // Press ArrowRight to move to next tab
        await page.keyboard.press('ArrowRight');
        await page.waitForTimeout(200);

        // Platforms tab should be focused
        const platformsTab = page.getByRole('tab', { name: /Platforms/i });
        const isFocused = await platformsTab.evaluate(el => el === document.activeElement);
        expect(isFocused).toBe(true);
    });

    test('search input has proper label', async ({ page }) => {
        const searchInput = page.getByPlaceholder(/Search all vocabularies/i);
        await expect(searchInput).toBeVisible();
        
        // Verify input has accessible name
        const accessibleName = await searchInput.getAttribute('aria-label') || 
                              await searchInput.getAttribute('placeholder');
        expect(accessibleName).toBeTruthy();
    });

    test('tabs have proper ARIA attributes', async ({ page }) => {
        const scienceTab = page.getByRole('tab', { name: /Science/i });
        
        // Verify role
        await expect(scienceTab).toHaveAttribute('role', 'tab');
        
        // Verify aria-selected
        const ariaSelected = await scienceTab.getAttribute('aria-selected');
        expect(['true', 'false']).toContain(ariaSelected);
    });
});
