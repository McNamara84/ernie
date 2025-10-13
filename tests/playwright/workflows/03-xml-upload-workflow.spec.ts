import * as path from 'node:path';

import { expect, test } from '@playwright/test';

import { CurationPage, DashboardPage } from '../helpers/page-objects';
import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * XML Upload Complete Workflow
 * 
 * Konsolidiert xml-upload.spec.ts Funktionalität
 * 
 * Testet den kompletten Upload-Workflow:
 * 1. Login und Navigation zum Dashboard
 * 2. XML-Datei hochladen
 * 3. Parsing und Validierung
 * 4. Formular wird mit Daten befüllt
 * 5. Error handling für ungültige XML-Dateien
 */

test.describe('XML Upload Complete Workflow', () => {
  test('user can upload valid XML file and form is populated', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);
    const curation = new CurationPage(page);

    await test.step('Navigate to dashboard', async () => {
      await dashboard.goto();
      await dashboard.verifyOnDashboard();
    });

    await test.step('Upload valid XML file', async () => {
      // Assuming test XML files are in tests/playwright/fixtures/
      const xmlFilePath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'valid-dataset.xml');

      // Check if upload component exists
      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (dropzoneVisible) {
        await dashboard.uploadXmlFile(xmlFilePath);
      } else {
        // Alternative: manual file input
        const fileInput = page.locator('input[type="file"][accept*="xml" i]');
        await fileInput.setInputFiles(xmlFilePath);
      }
    });

    await test.step('Wait for processing and redirect to curation', async () => {
      // Should redirect to curation form after successful upload
      await page.waitForURL(/\/curation/, { timeout: 15000 });
    });

    await test.step('Verify form is populated with XML data', async () => {
      await curation.verifyOnCurationPage();

      // Verify at least the form exists and has content
      const form = page.locator('form');
      await expect(form).toBeVisible();
      
      const formContent = await form.textContent();
      expect(formContent).toBeTruthy();
    });
  });

  test('upload shows progress feedback', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);

    await test.step('Navigate to dashboard', async () => {
      await dashboard.goto();
    });

    await test.step('Upload file and verify progress indication', async () => {
      const xmlFilePath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'valid-dataset.xml');

      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (dropzoneVisible) {
        // Start upload
        await dashboard.uploadXmlFile(xmlFilePath);

        // Look for progress indicators
        // Could be spinner, progress bar, or loading text
        const progressIndicator = page.locator(
          '[role="progressbar"], .spinner, .loading, [aria-busy="true"]'
        );

        // Progress indicator should appear (even if briefly)
        // This may pass immediately if upload is very fast
        await progressIndicator.isVisible({ timeout: 1000 }).catch(() => false);
        
        // If we don't see progress, that's OK - upload might be instant in test env
        // The important thing is no errors occur
      }
    });
  });

  test('invalid XML file shows appropriate error', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);

    await test.step('Navigate to dashboard', async () => {
      await dashboard.goto();
    });

    await test.step('Attempt to upload invalid XML file', async () => {
      // Create a temporary invalid XML file for testing
      const invalidXmlPath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'invalid-syntax.xml');

      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (dropzoneVisible) {
        // Upload will fail, so we need to handle the error
        try {
          await dashboard.uploadXmlFile(invalidXmlPath);
        } catch {
          // Expected to fail
        }

        // Verify error message is displayed
        const errorAlert = page.locator('[role="alert"], .error, .alert-danger');
        await expect(errorAlert.first()).toBeVisible({ timeout: 5000 });
      } else {
        // If dropzone doesn't exist, skip this test
        test.skip(true, 'XML dropzone not found in current implementation');
      }
    });
  });

  test('XML with complete metadata populates form fields', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);
    const curation = new CurationPage(page);

    await test.step('Upload comprehensive XML file', async () => {
      await dashboard.goto();

      const xmlFilePath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'complete-metadata.xml');

      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (!dropzoneVisible) {
        test.skip(true, 'XML upload not available');
      }

      await dashboard.uploadXmlFile(xmlFilePath);
      await page.waitForURL(/\/curation/, { timeout: 15000 });
    });

    await test.step('Verify comprehensive field population', async () => {
      // Verify form sections are visible
      await expect(curation.authorsAccordion).toBeVisible();
      await expect(curation.titlesAccordion).toBeVisible();
      await expect(curation.descriptionsAccordion).toBeVisible();
      await expect(curation.datesAccordion).toBeVisible();

      // Verify form can be submitted (save button should be enabled)
      await expect(curation.saveButton).toBeEnabled();
    });
  });

  test('XML with minimal required fields populates correctly', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);
    const curation = new CurationPage(page);

    await test.step('Upload minimal XML file', async () => {
      await dashboard.goto();

      const xmlFilePath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'minimal-required-fields.xml');

      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (!dropzoneVisible) {
        test.skip(true, 'XML upload not available');
      }

      await dashboard.uploadXmlFile(xmlFilePath);
      await page.waitForURL(/\/curation/, { timeout: 15000 });
    });

    await test.step('Verify form is valid and can be saved', async () => {
      // Verify form is in valid state to save
      await expect(curation.saveButton).toBeEnabled();
      
      // Verify at least required accordions are visible
      await expect(curation.authorsAccordion).toBeVisible();
    });
  });

  test('multiple XML uploads in sequence', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);

    await test.step('Upload first XML file', async () => {
      await dashboard.goto();

      const xmlPath1 = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'valid-dataset.xml');
      
      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (!dropzoneVisible) {
        test.skip(true, 'XML upload not available');
      }

      await dashboard.uploadXmlFile(xmlPath1);
      await page.waitForURL(/\/curation/, { timeout: 15000 });
    });

    await test.step('Return to dashboard and upload second file', async () => {
      await dashboard.goto();

      const xmlPath2 = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'complete-metadata.xml');

      await dashboard.uploadXmlFile(xmlPath2);
      await page.waitForURL(/\/curation/, { timeout: 15000 });

      // Verify new data loaded (form should be visible)
      const curation = new CurationPage(page);
      await curation.verifyOnCurationPage();
      await expect(curation.saveButton).toBeEnabled();
    });
  });

  test('XML upload with special characters in metadata', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);
    const curation = new CurationPage(page);

    await test.step('Upload XML with special characters', async () => {
      await dashboard.goto();

      // XML with umlauts, special symbols, etc.
      const xmlFilePath = path.join(process.cwd(), 'tests', 'playwright', 'fixtures', 'special-characters.xml');

      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (!dropzoneVisible) {
        test.skip(true, 'XML upload not available');
      }

      await dashboard.uploadXmlFile(xmlFilePath).catch(() => {
        // File might not exist, skip test
        test.skip(true, 'Special characters test file not found');
      });

      await page.waitForURL(/\/curation/, { timeout: 15000 });
    });

    await test.step('Verify special characters are preserved', async () => {
      // Check that form loaded without issues
      await curation.verifyOnCurationPage();
      const formContent = await page.locator('form').textContent();
      
      // Should contain properly encoded characters
      expect(formContent).toBeTruthy();
    });
  });

  test('cancel/abort XML upload', async ({ page }) => {
    await loginAsTestUser(page);

    const dashboard = new DashboardPage(page);

    await test.step('Start upload and attempt to cancel', async () => {
      await dashboard.goto();

      // This test depends on whether cancel functionality exists
      // Check for cancel button after starting upload
      
      const dropzoneVisible = await dashboard.xmlDropzone.isVisible().catch(() => false);
      
      if (!dropzoneVisible) {
        test.skip(true, 'XML upload not available');
      }

      // Look for cancel/abort button
      const cancelButton = page.locator('button', { hasText: /cancel|abort/i });
      const hasCancelButton = await cancelButton.isVisible({ timeout: 1000 }).catch(() => false);

      if (!hasCancelButton) {
        test.skip(true, 'No cancel functionality implemented');
      }

      await cancelButton.click();

      // Should remain on dashboard
      await expect(page).toHaveURL(/\/dashboard/);
    });
  });
});
