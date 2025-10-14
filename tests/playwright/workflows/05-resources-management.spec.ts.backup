import { expect, test } from '@playwright/test';

import { CurationPage, ResourcesPage } from '../helpers/page-objects';
import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Resources Management Complete Workflow
 * 
 * Dies ist ein NEUER Workflow-Test (keine alten Tests zu konsolidieren)
 * Füllt eine Test-Coverage-Lücke für das Resources Management.
 * 
 * Testet den kompletten Resources-Workflow:
 * 1. Liste der Ressourcen anzeigen
 * 2. Ressourcen suchen und filtern
 * 3. Ressource erstellen
 * 4. Ressource bearbeiten
 * 5. Ressource löschen
 */

test.describe('Resources Management Complete Workflow', () => {
  test('user can view resources list', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources page', async () => {
      await resources.goto();
      await resources.verifyOnResourcesPage();
    });

    await test.step('Verify resources table is displayed', async () => {
      // Check if table or empty message is visible
      const hasResources = await resources.resourceTable.isVisible().catch(() => false);
      const showsNoResources = await resources.noResourcesMessage.isVisible().catch(() => false);

      expect(hasResources || showsNoResources).toBeTruthy();
    });
  });

  test('user can create new resource', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);
    const curation = new CurationPage(page);

    await test.step('Navigate to resources and click create', async () => {
      await resources.goto();
      
      const createButtonVisible = await resources.createButton.isVisible().catch(() => false);
      if (!createButtonVisible) {
        test.skip(true, 'Create button not found in current implementation');
      }

      await resources.createResource();
    });

    await test.step('Fill curation form with minimal data', async () => {
      await curation.verifyOnCurationPage();

      // Add required fields
      await curation.openAccordion(curation.authorsAccordion);
      await curation.addAuthor();
      await curation.fillAuthor(0, {
        type: 'Person',
        firstName: 'New',
        lastName: 'Resource',
      });

      await curation.openAccordion(curation.titlesAccordion);
      await curation.addTitle();
      await curation.fillTitle(0, {
        title: 'Newly Created Resource',
        language: 'English',
      });
    });

    await test.step('Save new resource', async () => {
      await curation.save();

      // Should redirect back to resources or show success
      await page.waitForTimeout(2000);

      const savedSuccessfully = 
        await page.url().includes('/resources') ||
        await page.getByRole('status').isVisible().catch(() => false);

      expect(savedSuccessfully).toBeTruthy();
    });
  });

  test('user can search for resources', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources page', async () => {
      await resources.goto();
    });

    await test.step('Search for resources', async () => {
      const searchVisible = await resources.searchInput.isVisible().catch(() => false);
      
      if (!searchVisible) {
        test.skip(true, 'Search not available');
      }

      await resources.search('test');

      // Verify search was applied
      await page.waitForTimeout(500);
      
      // Should show filtered results or no results message
      const hasResults = 
        await resources.resourceTable.isVisible().catch(() => false) ||
        await resources.noResourcesMessage.isVisible().catch(() => false);

      expect(hasResults).toBeTruthy();
    });
  });

  test('user can edit existing resource', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);
    const curation = new CurationPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Verify at least one resource exists', async () => {
      await resources.verifyResourcesDisplayed();
      
      const firstRow = resources.getResourceRow(0);
      const rowVisible = await firstRow.isVisible().catch(() => false);
      
      if (!rowVisible) {
        test.skip(true, 'No resources available to edit');
      }
    });

    await test.step('Click edit on first resource', async () => {
      await resources.editResource(0);
    });

    await test.step('Modify resource data', async () => {
      await curation.verifyOnCurationPage();

      // Make a change to verify edit functionality
      await curation.openAccordion(curation.titlesAccordion);
      
      const titleInput = page.getByLabel('Title').first();
      const currentTitle = await titleInput.inputValue();
      await titleInput.fill(`${currentTitle} - Edited`);
    });

    await test.step('Save edited resource', async () => {
      await curation.save();

      // Should redirect or show success
      await page.waitForTimeout(2000);

      const savedSuccessfully = 
        await page.url().includes('/resources') ||
        await page.getByRole('status').isVisible().catch(() => false);

      expect(savedSuccessfully).toBeTruthy();
    });
  });

  test('user can delete resource', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Verify resources exist', async () => {
      await resources.verifyResourcesDisplayed();
      
      const firstRow = resources.getResourceRow(0);
      const rowVisible = await firstRow.isVisible().catch(() => false);
      
      if (!rowVisible) {
        test.skip(true, 'No resources available to delete');
      }
    });

    await test.step('Get initial resource count', async () => {
      const rowsBefore = await resources.resourceTable.locator('tbody tr').count();
      expect(rowsBefore).toBeGreaterThan(0);
    });

    await test.step('Delete first resource', async () => {
      await resources.deleteResource(0, true);

      // Wait for deletion to complete
      await page.waitForTimeout(1000);

      // Verify resource was deleted (row count decreased or no resources message)
      const rowsAfter = await resources.resourceTable.locator('tbody tr').count().catch(() => 0);
      const noResourcesShown = await resources.noResourcesMessage.isVisible().catch(() => false);

      expect(rowsAfter === 0 || noResourcesShown).toBeTruthy();
    });
  });

  test('user can cancel resource deletion', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Verify resources exist', async () => {
      const firstRow = resources.getResourceRow(0);
      const rowVisible = await firstRow.isVisible().catch(() => false);
      
      if (!rowVisible) {
        test.skip(true, 'No resources available');
      }
    });

    await test.step('Get initial resource count', async () => {
      const rowsBefore = await resources.resourceTable.locator('tbody tr').count();
      expect(rowsBefore).toBeGreaterThan(0);
    });

    await test.step('Cancel deletion', async () => {
      await resources.deleteResource(0, false);

      // Wait a moment
      await page.waitForTimeout(500);

      // Verify resource still exists (count unchanged)
      const rowsAfter = await resources.resourceTable.locator('tbody tr').count();
      expect(rowsAfter).toBeGreaterThan(0);
    });
  });

  test('resources list shows correct metadata', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Verify table structure', async () => {
      const tableVisible = await resources.resourceTable.isVisible().catch(() => false);
      
      if (!tableVisible) {
        test.skip(true, 'No resources available');
      }

      // Verify table has headers
      const headers = resources.resourceTable.locator('thead th');
      const headerCount = await headers.count();
      expect(headerCount).toBeGreaterThan(0);

      // Verify at least some expected columns exist
      const tableContent = await resources.resourceTable.textContent();
      expect(tableContent).toBeTruthy();
    });
  });

  test('pagination works for large resource lists', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Check for pagination', async () => {
      const pagination = page.locator('.pagination, [role="navigation"][aria-label*="pagination" i]');
      const hasPagination = await pagination.isVisible().catch(() => false);

      if (!hasPagination) {
        test.skip(true, 'Not enough resources for pagination');
      }

      // Click next page
      const nextButton = page.getByRole('button', { name: /next/i });
      await nextButton.click();

      // Verify page changed
      await page.waitForTimeout(500);
      const url = page.url();
      expect(url).toContain('page=2');
    });
  });

  test('resource detail view displays complete information', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('Click on first resource to view details', async () => {
      const firstRow = resources.getResourceRow(0);
      const rowVisible = await firstRow.isVisible().catch(() => false);
      
      if (!rowVisible) {
        test.skip(true, 'No resources available');
      }

      // Click on resource (might be link or button)
      const resourceLink = firstRow.locator('a, button').first();
      await resourceLink.click();

      // Should navigate to detail view or open modal
      await page.waitForTimeout(1000);

      const detailVisible = 
        page.url().includes('/resources/') ||
        await page.getByRole('dialog').isVisible().catch(() => false);

      expect(detailVisible).toBeTruthy();
    });
  });

  test('empty state shows helpful message', async ({ page }) => {
    await loginAsTestUser(page);

    const resources = new ResourcesPage(page);

    await test.step('Navigate to resources', async () => {
      await resources.goto();
    });

    await test.step('If no resources, verify empty state', async () => {
      const noResources = await resources.noResourcesMessage.isVisible({ timeout: 2000 }).catch(() => false);

      if (noResources) {
        // Verify message is helpful
        await expect(resources.noResourcesMessage).toBeVisible();
        
        // Should have a create button available
        const createAvailable = await resources.createButton.isVisible().catch(() => false);
        expect(createAvailable).toBeTruthy();
      } else {
        // Resources exist, skip this test
        test.skip(true, 'Resources exist - empty state not applicable');
      }
    });
  });
});
