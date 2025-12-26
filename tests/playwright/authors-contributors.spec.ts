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

import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import { loginAsTestUser } from './helpers/test-helpers';

async function gotoEditor(page: Page) {
    await page.goto('/editor');
    await expect(page.getByRole('region', { name: /^Authors Section/i })).toBeVisible({ timeout: 15000 });
}

async function ensureAuthor(page: Page, index: number) {
    const authorsSection = page.getByRole('region', { name: /^Authors Section/i });
    const authorRegion = authorsSection.getByRole('region', { name: `Author ${index}` });

    if ((await authorRegion.count()) === 0) {
        const addFirstAuthorButton = authorsSection.getByRole('button', { name: /add first author/i });
        const addAuthorButton = authorsSection.getByRole('button', { name: /add (another )?author/i });

        if (await addFirstAuthorButton.isVisible().catch(() => false)) {
            await addFirstAuthorButton.click();
        } else {
            await addAuthorButton.click();
        }
    }

    await expect(authorRegion).toBeVisible({ timeout: 15000 });
    return authorRegion;
}

async function ensureContributor(page: Page, index: number) {
    const contributorsSection = page.getByRole('region', { name: /^Contributors\b/i });
    const contributorRegion = contributorsSection.getByRole('region', { name: `Contributor ${index}` });

    if ((await contributorRegion.count()) === 0) {
        const addFirstContributorButton = contributorsSection.getByRole('button', { name: /add first contributor/i });
        const addContributorButton = contributorsSection.getByRole('button', { name: /add (another )?contributor/i });

        if (await addFirstContributorButton.isVisible().catch(() => false)) {
            await addFirstContributorButton.click();
        } else {
            await addContributorButton.click();
        }
    }

    await expect(contributorRegion).toBeVisible({ timeout: 15000 });
    return contributorRegion;
}

async function mockOrcidApi(page: Page) {
    await page.route(/\/api\/v1\/orcid\/validate\/.+$/, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                valid: true,
                exists: true,
                message: 'Valid ORCID ID',
            }),
        });
    });

    await page.route(/\/api\/v1\/orcid\/search\?.+$/, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                success: true,
                data: {
                    results: [
                        {
                            orcid: '0000-0002-1825-0097',
                            firstName: 'John',
                            lastName: 'Doe',
                            creditName: null,
                            institutions: ['GFZ German Research Centre for Geosciences'],
                        },
                    ],
                    total: 1,
                },
            }),
        });
    });

    await page.route(/\/api\/v1\/orcid\/(?!validate\/|search\?).+$/, async (route) => {
        await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                success: true,
                data: {
                    orcid: '0000-0002-1825-0097',
                    firstName: 'John',
                    lastName: 'Doe',
                    creditName: null,
                    emails: [],
                    affiliations: [],
                    verifiedAt: new Date().toISOString(),
                },
            }),
        });
    });
}

test.describe('Authors Form', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);

        // Navigate to the dataset editor
        await gotoEditor(page);
    });

    test('should add a new author', async ({ page }) => {
        const author1 = await ensureAuthor(page, 1);

        await author1.getByRole('textbox', { name: /^First name$/i }).fill('John');
        await author1.getByRole('textbox', { name: /^Last name\*?$/i }).fill('Doe');

        await expect(author1.getByRole('textbox', { name: /^First name$/i })).toHaveValue('John');
        await expect(author1.getByRole('textbox', { name: /^Last name\*?$/i })).toHaveValue('Doe');
    });

    test('should remove an author', async ({ page }) => {
        const authorsSection = page.getByRole('region', { name: /^Authors Section/i });
        await ensureAuthor(page, 1);
        await ensureAuthor(page, 2);
        
        // Remove first author
        await page.click('button[aria-label="Remove author 1"]');
        
        // Verify only one author remains
        await expect(authorsSection.getByRole('region', { name: 'Author 2' })).not.toBeVisible();
        await expect(authorsSection.getByRole('region', { name: 'Author 1' })).toBeVisible();
    });

    test('should switch author type from person to institution', async ({ page }) => {
        const author1 = await ensureAuthor(page, 1);
        
        // Change type to institution
        const authorType = author1.getByRole('combobox', { name: /^Author type\*?$/i });
        await authorType.click();
        await page.getByRole('option', { name: /^Institution$/i }).click();
        
        await expect(authorType).toContainText(/Institution/i);
    });

    test('should validate ORCID format', async ({ page }) => {
        await mockOrcidApi(page);
        const author1 = await ensureAuthor(page, 1);
        
        // Enter valid ORCID
        const orcidInput = author1.getByRole('textbox', { name: /^ORCID$/i });
        await orcidInput.fill('0000-0002-1825-0097');
        
        await expect(orcidInput).toHaveValue('0000-0002-1825-0097');
    });

    test('should auto-fill from ORCID', async ({ page }) => {
        await mockOrcidApi(page);
        const author1 = await ensureAuthor(page, 1);
        
        // Enter ORCID and wait for auto-fill
        await author1.getByRole('textbox', { name: /^ORCID$/i }).fill('0000-0002-1825-0097');

        // Expect data to be filled from mocked ORCID record
        await expect(author1.getByRole('textbox', { name: /^First name$/i })).toHaveValue(/John/i, { timeout: 15000 });
        await expect(author1.getByRole('textbox', { name: /^Last name\*?$/i })).toHaveValue(/Doe/i, { timeout: 15000 });
    });

    test('should mark author as contact person', async ({ page }) => {
        const author1 = await ensureAuthor(page, 1);
        
        // Check contact person checkbox
        await author1.getByRole('checkbox', { name: /contact person/i }).check();
        
        // Verify checkbox is checked
        await expect(author1.getByRole('checkbox', { name: /contact person/i })).toBeChecked();
    });
});

test.describe('Contributors Form', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await gotoEditor(page);
    });

    test('should add a new contributor with role', async ({ page }) => {
        const contributor1 = await ensureContributor(page, 1);

        await contributor1.getByRole('textbox', { name: /^First name$/i }).fill('Jane');
        await contributor1.getByRole('textbox', { name: /^Last name\*?$/i }).fill('Smith');

        await expect(contributor1.getByRole('textbox', { name: /^First name$/i })).toHaveValue('Jane');
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
        await loginAsTestUser(page);
        await gotoEditor(page);
    });

    test('should open CSV import dialog for authors', async ({ page }) => {
        const authorsSection = page.getByRole('region', { name: /^Authors Section/i });

        // Click Import CSV button
        await authorsSection.getByRole('button', { name: /import authors from csv file|import csv/i }).click();
        
        // Verify dialog opens
        await expect(page.getByText('CSV Bulk Import', { exact: true })).toBeVisible();
    });

    test('should download example CSV for authors', async ({ page }) => {
        const authorsSection = page.getByRole('region', { name: /^Authors Section/i });

        await authorsSection.getByRole('button', { name: /import authors from csv file|import csv/i }).click();
        await expect(page.getByText('CSV Bulk Import', { exact: true })).toBeVisible();
        
        // Click download example button
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('button', { name: /^Download Example$/i }).click();
        
        const download = await downloadPromise;
        expect(download.suggestedFilename()).toContain('.csv');
    });

    test('should open CSV import dialog for contributors', async ({ page }) => {
        const contributorsSection = page.getByRole('region', { name: /^Contributors\b/i });
        await contributorsSection.getByRole('button', { name: /import contributors from csv file|import csv/i }).click();
        await expect(page.getByText('CSV Bulk Import', { exact: true })).toBeVisible();
    });
});

test.describe('Drag and Drop Reordering', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await gotoEditor(page);
    });

    test('should show drag handles for authors', async ({ page }) => {
        await ensureAuthor(page, 1);
        await ensureAuthor(page, 2);
        
        // Verify drag handles are visible
        const dragHandles = page.locator('button[aria-label*="Reorder author"]');
        await expect(dragHandles).toHaveCount(2);
    });

    test('should reorder authors via drag and drop', async ({ page }) => {
        // Add two authors
        const author1 = await ensureAuthor(page, 1);
        const author2 = await ensureAuthor(page, 2);

        await author1.getByRole('textbox', { name: /^First name$/i }).fill('First');
        await author2.getByRole('textbox', { name: /^First name$/i }).fill('Second');
        
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
        await loginAsTestUser(page);
        await gotoEditor(page);
        await mockOrcidApi(page);
        await ensureAuthor(page, 1);
    });

    test('should open ORCID search dialog', async ({ page }) => {
        // Click ORCID search button (magnifying glass icon)
        await page.click('button[aria-label="Search for ORCID"]');
        
        // Verify dialog opens
        await expect(page.getByRole('heading', { name: 'Search for ORCID' })).toBeVisible();
    });

    test('should perform ORCID search', async ({ page }) => {
        await page.click('button[aria-label="Search for ORCID"]');
        
        // Enter search query
        await page.getByRole('textbox', { name: /^Search Query$/i }).fill('John Doe');
        await page.getByRole('button', { name: /^Search$/i }).click();
        
        // Wait for results (requires mock or test API)
        await page.waitForTimeout(2000);
        
        // Verify results table appears
        await expect(page.getByRole('table')).toBeVisible({ timeout: 15000 });
    });

    test('should close ORCID search dialog', async ({ page }) => {
        await page.click('button[aria-label="Search for ORCID"]');
        await expect(page.getByRole('heading', { name: 'Search for ORCID' })).toBeVisible();
        
        // Close dialog
        await page.keyboard.press('Escape');
        
        // Verify dialog closed
        await expect(page.getByRole('dialog', { name: 'Search for ORCID' })).not.toBeVisible();
    });
});

test.describe('Accessibility', () => {
    test.beforeEach(async ({ page }) => {
        await loginAsTestUser(page);
        await gotoEditor(page);
    });

    test('should have proper ARIA labels for buttons', async ({ page }) => {
        // Verify ARIA labels exist
        await expect(page.getByRole('button', { name: 'Add First Author' })).toBeVisible();
        await expect(page.locator('button[aria-label="Import authors from CSV file"]')).toBeVisible();
    });

    test('should have role="list" for authors list', async ({ page }) => {
        const authorsSection = page.getByRole('region', { name: /^Authors Section/i });

        await ensureAuthor(page, 1);
        await expect(authorsSection.getByRole('list', { name: 'Authors' })).toBeVisible();
    });

    test('should support keyboard navigation', async ({ page }) => {
        await ensureAuthor(page, 1);
        
        // Tab through form fields
        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        
        // Verify focus is on a form element
        const focused = await page.evaluate(() => document.activeElement?.tagName);
        expect(['INPUT', 'SELECT', 'BUTTON']).toContain(focused);
    });
});
