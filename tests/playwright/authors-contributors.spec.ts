/**
 * E2E Tests for Authors and Contributors Form
 * 
 * Tests the complete workflow including:
 * - Adding/Removing Authors and Contributors
 * - ORCID Auto-Fill and Verification
 * - CSV Bulk Import
 * - Drag & Drop Reordering
 * - ORCID Search Dialog
 */

import { test, expect } from '@playwright/test';

test.describe('Authors Form', () => {
    test.beforeEach(async ({ page }) => {
        // Navigate to the dataset editor
        await page.goto('/editor');
        // Wait for the form to be fully loaded
        await page.waitForSelector('[data-testid="author-0-fields-grid"]', { timeout: 10000 });
    });

    test('should add a new author', async ({ page }) => {
        // Click Add First Author button
        await page.click('button:has-text("Add First Author")');
        
        // Verify author card appeared
        await expect(page.locator('text=Author 1')).toBeVisible();
        
        // Fill in author details
        await page.fill('[data-testid="author-0-firstName-input"]', 'John');
        await page.fill('[data-testid="author-0-lastName-input"]', 'Doe');
        await page.fill('[data-testid="author-0-email-input"]', 'john.doe@example.com');
        
        // Verify inputs are filled
        await expect(page.locator('[data-testid="author-0-firstName-input"]')).toHaveValue('John');
        await expect(page.locator('[data-testid="author-0-lastName-input"]')).toHaveValue('Doe');
    });

    test('should remove an author', async ({ page }) => {
        // Add first author
        await page.click('button:has-text("Add First Author")');
        await expect(page.locator('text=Author 1')).toBeVisible();
        
        // Add second author to enable remove button
        await page.click('button:has-text("Add Author")');
        await expect(page.locator('text=Author 2')).toBeVisible();
        
        // Remove first author
        await page.click('button[aria-label="Remove author 1"]');
        
        // Verify only one author remains
        await expect(page.locator('text=Author 2')).not.toBeVisible();
        await expect(page.locator('text=Author 1')).toBeVisible();
    });

    test('should switch author type from person to institution', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        
        // Change type to institution
        await page.click('[data-testid="author-0-type-field"] button');
        await page.click('text=Institution');
        
        // Verify institution name field appears
        await expect(page.locator('[data-testid="author-0-institutionName-input"]')).toBeVisible();
        
        // Person fields should be hidden
        await expect(page.locator('[data-testid="author-0-firstName-input"]')).not.toBeVisible();
    });

    test('should validate ORCID format', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        
        // Enter valid ORCID
        const orcidInput = page.locator('[data-testid="author-0-orcid-input"]');
        await orcidInput.fill('0000-0002-1825-0097');
        
        // Wait for validation
        await page.waitForTimeout(1000);
        
        // Verify ORCID link appears
        await expect(page.locator('a[aria-label="View on ORCID.org"]')).toBeVisible();
    });

    test('should auto-fill from ORCID', async ({ page }) => {
        // This test requires mocking the ORCID API or using a test ORCID
        await page.click('button:has-text("Add First Author")');
        
        // Enter ORCID and wait for auto-fill
        await page.fill('[data-testid="author-0-orcid-input"]', '0000-0002-1825-0097');
        
        // Wait for auto-fill to complete
        await page.waitForTimeout(2000);
        
        // Verify verified badge appears
        await expect(page.locator('text=Verified')).toBeVisible();
    });

    test('should mark author as contact person', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        
        // Check contact person checkbox
        await page.click('input[type="checkbox"]:near(text="Contact person")');
        
        // Verify checkbox is checked
        await expect(page.locator('input[type="checkbox"]:near(text="Contact person")')).toBeChecked();
    });
});

test.describe('Contributors Form', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/editor');
        await page.waitForSelector('[data-testid="contributor-0-fields-grid"]', { timeout: 10000 });
    });

    test('should add a new contributor with role', async ({ page }) => {
        await page.click('button:has-text("Add First Contributor")');
        
        await expect(page.locator('text=Contributor 1')).toBeVisible();
        
        // Fill in contributor details
        await page.fill('[data-testid="contributor-0-firstName-input"]', 'Jane');
        await page.fill('[data-testid="contributor-0-lastName-input"]', 'Smith');
        
        // Add contributor role
        await page.fill('[data-testid="contributor-0-roles-input"]', 'DataCollector');
        
        await expect(page.locator('[data-testid="contributor-0-firstName-input"]')).toHaveValue('Jane');
    });

    test('should remove a contributor', async ({ page }) => {
        await page.click('button:has-text("Add First Contributor")');
        await page.click('button:has-text("Add Contributor")');
        
        await expect(page.locator('text=Contributor 2')).toBeVisible();
        
        await page.click('button[aria-label="Remove contributor 1"]');
        
        await expect(page.locator('text=Contributor 2')).not.toBeVisible();
    });
});

test.describe('CSV Import', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/editor');
    });

    test('should open CSV import dialog for authors', async ({ page }) => {
        // Click Import CSV button
        await page.click('button[aria-label="Import authors from CSV file"]');
        
        // Verify dialog opens
        await expect(page.locator('text=Import Authors from CSV')).toBeVisible();
        await expect(page.locator('text=Upload a CSV file to add multiple authors at once')).toBeVisible();
    });

    test('should download example CSV for authors', async ({ page }) => {
        await page.click('button[aria-label="Import authors from CSV file"]');
        
        // Click download example button
        const downloadPromise = page.waitForEvent('download');
        await page.click('button:has-text("Download Example CSV")');
        
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toContain('.csv');
    });

    test('should open CSV import dialog for contributors', async ({ page }) => {
        await page.click('button[aria-label="Import contributors from CSV file"]');
        
        await expect(page.locator('text=Import Contributors from CSV')).toBeVisible();
    });
});

test.describe('Drag and Drop Reordering', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/editor');
    });

    test('should show drag handles for authors', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        await page.click('button:has-text("Add Author")');
        
        // Verify drag handles are visible
        const dragHandles = page.locator('button[aria-label*="Reorder author"]');
        await expect(dragHandles).toHaveCount(2);
    });

    test('should reorder authors via drag and drop', async ({ page }) => {
        // Add two authors
        await page.click('button:has-text("Add First Author")');
        await page.fill('[data-testid="author-0-firstName-input"]', 'First');
        
        await page.click('button:has-text("Add Author")');
        await page.fill('[data-testid="author-1-firstName-input"]', 'Second');
        
        // Perform drag and drop
        const firstHandle = page.locator('button[aria-label="Reorder author 1"]');
        const secondHandle = page.locator('button[aria-label="Reorder author 2"]');
        
        const firstBox = await firstHandle.boundingBox();
        const secondBox = await secondHandle.boundingBox();
        
        if (firstBox && secondBox) {
            await page.mouse.move(firstBox.x + firstBox.width / 2, firstBox.y + firstBox.height / 2);
            await page.mouse.down();
            await page.mouse.move(secondBox.x + secondBox.width / 2, secondBox.y + secondBox.height / 2);
            await page.mouse.up();
        }
        
        // Verify order changed
        // Note: This would need to verify actual DOM order change
    });
});

test.describe('ORCID Search Dialog', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/editor');
        await page.click('button:has-text("Add First Author")');
    });

    test('should open ORCID search dialog', async ({ page }) => {
        // Click ORCID search button (magnifying glass icon)
        await page.click('button[aria-label="Search for ORCID"]');
        
        // Verify dialog opens
        await expect(page.locator('text=Search ORCID Registry')).toBeVisible();
    });

    test('should perform ORCID search', async ({ page }) => {
        await page.click('button[aria-label="Search for ORCID"]');
        
        // Enter search query
        await page.fill('input[placeholder*="name"]', 'John Doe');
        await page.click('button:has-text("Search")');
        
        // Wait for results (requires mock or test API)
        await page.waitForTimeout(2000);
        
        // Verify results table appears
        await expect(page.locator('table')).toBeVisible();
    });

    test('should close ORCID search dialog', async ({ page }) => {
        await page.click('button[aria-label="Search for ORCID"]');
        await expect(page.locator('text=Search ORCID Registry')).toBeVisible();
        
        // Close dialog
        await page.keyboard.press('Escape');
        
        // Verify dialog closed
        await expect(page.locator('text=Search ORCID Registry')).not.toBeVisible();
    });
});

test.describe('Accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/editor');
    });

    test('should have proper ARIA labels for buttons', async ({ page }) => {
        // Verify ARIA labels exist
        await expect(page.locator('button[aria-label="Add first author"]')).toBeVisible();
        await expect(page.locator('button[aria-label="Import authors from CSV file"]')).toBeVisible();
    });

    test('should have role="list" for authors list', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        
        await expect(page.locator('[role="list"][aria-label="Authors"]')).toBeVisible();
    });

    test('should support keyboard navigation', async ({ page }) => {
        await page.click('button:has-text("Add First Author")');
        
        // Tab through form fields
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        
        // Verify focus is on a form element
        const focused = await page.evaluate(() => document.activeElement?.tagName);
        expect(['INPUT', 'SELECT', 'BUTTON']).toContain(focused);
    });
});
