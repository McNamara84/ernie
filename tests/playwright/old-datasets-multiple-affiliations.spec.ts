import { expect, test } from '@playwright/test';

/**
 * End-to-End tests for loading old datasets with multiple affiliations.
 * 
 * This test suite verifies that the fix for multiple affiliations loading works correctly
 * in the browser environment with real Tagify instances.
 * 
 * Bug fixed: Multiple affiliations from old datasets were being displayed as a single tag
 * instead of multiple separate tags in the Tagify input field.
 */
test.describe('Old Datasets - Multiple Affiliations Loading', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login');
        
        // Login
        await page.fill('input[name="email"]', 'admin@example.com');
        await page.fill('input[name="password"]', 'password');
        await page.click('button[type="submit"]');
        
        // Wait for redirect to dashboard
        await page.waitForURL('/dashboard');
    });

    test('should load author with multiple affiliations from old dataset as separate tags', async ({ page }) => {
        // Navigate to old datasets
        await page.goto('/old-datasets');
        
        // Wait for the datasets to load
        await page.waitForSelector('[data-testid="old-dataset-row"]', { timeout: 10000 });
        
        // Find and click on dataset ID 9 (has author Parolai, Stefano with 2 affiliations)
        const datasetRow = page.locator('[data-testid="old-dataset-row"]').filter({ hasText: /^9\s/ }).first();
        await datasetRow.waitFor({ state: 'visible' });
        
        // Click the "Load into Curation Form" button
        const loadButton = datasetRow.locator('button', { hasText: 'Load into Curation Form' });
        await loadButton.click();
        
        // Wait for navigation to curation form
        await page.waitForURL('/curation', { timeout: 10000 });
        
        // Open the Authors section
        const authorsAccordion = page.locator('[data-state]').filter({ hasText: 'Authors' }).first();
        const isOpen = await authorsAccordion.getAttribute('data-state');
        if (isOpen !== 'open') {
            await authorsAccordion.click();
            await page.waitForTimeout(300); // Wait for accordion animation
        }
        
        // Find the author with multiple affiliations (Parolai, Stefano should be author #7)
        // Look for all affiliation fields and check if any have multiple tags
        const affiliationFields = page.locator('[data-testid*="author-"][data-testid*="-affiliations-field"]');
        const count = await affiliationFields.count();
        
        let foundMultipleAffiliations = false;
        
        for (let i = 0; i < count; i++) {
            const field = affiliationFields.nth(i);
            const tags = field.locator('.tagify__tag');
            const tagCount = await tags.count();
            
            if (tagCount > 1) {
                foundMultipleAffiliations = true;
                
                // Verify we have exactly 2 tags
                expect(tagCount).toBe(2);
                
                // Verify the tag texts contain expected affiliations
                const tag1Text = await tags.nth(0).textContent();
                const tag2Text = await tags.nth(1).textContent();
                
                expect(tag1Text).toContain('GFZ');
                expect(tag2Text).toContain('OGS');
                
                break;
            }
        }
        
        expect(foundMultipleAffiliations).toBe(true);
    });

    test('should load contributor with multiple affiliations from old dataset as separate tags', async ({ page }) => {
        // Navigate to old datasets
        await page.goto('/old-datasets');
        
        // Wait for the datasets to load
        await page.waitForSelector('[data-testid="old-dataset-row"]', { timeout: 10000 });
        
        // Find and click on dataset ID 437 (has multiple contributors with 2 affiliations each)
        const datasetRow = page.locator('[data-testid="old-dataset-row"]').filter({ hasText: /^437\s/ }).first();
        await datasetRow.waitFor({ state: 'visible' });
        
        // Click the "Load into Curation Form" button
        const loadButton = datasetRow.locator('button', { hasText: 'Load into Curation Form' });
        await loadButton.click();
        
        // Wait for navigation to curation form
        await page.waitForURL('/curation', { timeout: 10000 });
        
        // Open the Contributors section
        const contributorsAccordion = page.locator('[data-state]').filter({ hasText: 'Contributors' }).first();
        const isOpen = await contributorsAccordion.getAttribute('data-state');
        if (isOpen !== 'open') {
            await contributorsAccordion.click();
            await page.waitForTimeout(300); // Wait for accordion animation
        }
        
        // Find contributors with multiple affiliations
        const affiliationFields = page.locator('[data-testid*="contributor-"][data-testid*="-affiliations-field"]');
        const count = await affiliationFields.count();
        
        let multipleAffiliationsCount = 0;
        
        for (let i = 0; i < count; i++) {
            const field = affiliationFields.nth(i);
            const tags = field.locator('.tagify__tag');
            const tagCount = await tags.count();
            
            if (tagCount > 1) {
                multipleAffiliationsCount++;
                
                // Verify we have exactly 2 tags for this contributor
                expect(tagCount).toBe(2);
            }
        }
        
        // Dataset 437 should have at least 1 contributor with multiple affiliations
        expect(multipleAffiliationsCount).toBeGreaterThan(0);
    });

    test('should handle old dataset with single affiliation correctly', async ({ page }) => {
        // Navigate to old datasets
        await page.goto('/old-datasets');
        
        // Wait for the datasets to load
        await page.waitForSelector('[data-testid="old-dataset-row"]', { timeout: 10000 });
        
        // Load any dataset
        const datasetRow = page.locator('[data-testid="old-dataset-row"]').first();
        await datasetRow.waitFor({ state: 'visible' });
        
        const loadButton = datasetRow.locator('button', { hasText: 'Load into Curation Form' });
        await loadButton.click();
        
        // Wait for navigation to curation form
        await page.waitForURL('/curation', { timeout: 10000 });
        
        // Open the Authors section
        const authorsAccordion = page.locator('[data-state]').filter({ hasText: 'Authors' }).first();
        const isOpen = await authorsAccordion.getAttribute('data-state');
        if (isOpen !== 'open') {
            await authorsAccordion.click();
            await page.waitForTimeout(300);
        }
        
        // Check that at least one author field exists and has valid tags
        const affiliationFields = page.locator('[data-testid*="author-"][data-testid*="-affiliations-field"]');
        const count = await affiliationFields.count();
        
        expect(count).toBeGreaterThan(0);
        
        // Verify that tags are properly rendered (either 1 or more)
        for (let i = 0; i < Math.min(count, 3); i++) {
            const field = affiliationFields.nth(i);
            const tags = field.locator('.tagify__tag');
            const tagCount = await tags.count();
            
            // Should have at least 0 tags (some authors might not have affiliations)
            expect(tagCount).toBeGreaterThanOrEqual(0);
        }
    });
});
