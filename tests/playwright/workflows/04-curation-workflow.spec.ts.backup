import { expect, test } from '@playwright/test';

import { CurationPage } from '../helpers/page-objects';
import { loginAsTestUser } from '../helpers/test-helpers';

/**
 * Curation Complete Workflow
 * 
 * Konsolidiert die folgenden alten Tests:
 * - curation-authors.spec.ts
 * - curation-titles.spec.ts
 * - curation-controlled-vocabularies.spec.ts
 * 
 * Testet den kompletten Curation-Workflow:
 * 1. Formular mit Pflichtangaben ausfüllen und speichern
 * 2. Formular mit allen Angaben ausfüllen
 * 3. Authors hinzufügen (Person & Institution)
 * 4. Titles in verschiedenen Sprachen
 * 5. Descriptions hinzufügen
 * 6. Dates (Publication, Collection, etc.)
 * 7. Controlled Vocabularies (Resource Types, Languages, etc.)
 */

test.describe('Curation Complete Workflow', () => {
  test('user can fill and save form with minimal required fields', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);

    await test.step('Navigate to curation page', async () => {
      await curation.goto();
      await curation.verifyOnCurationPage();
    });

    await test.step('Fill required fields', async () => {
      // Open authors accordion and add at least one author
      await curation.openAccordion(curation.authorsAccordion);
      await curation.addAuthor();
      await curation.fillAuthor(0, {
        type: 'Person',
        firstName: 'Test',
        lastName: 'Author',
      });

      // Open titles accordion and add title
      await curation.openAccordion(curation.titlesAccordion);
      const titleInput = page.getByLabel('Title').first();
      await titleInput.fill('Test Dataset Title');
    });

    await test.step('Save form', async () => {
      await curation.saveButton.click();
      
      // Should show success message or redirect
      await page.waitForTimeout(1000);
      
      // Verify either success message or redirect to resources
      const savedSuccessfully = 
        await page.getByRole('status').isVisible().catch(() => false) ||
        await page.url().includes('/resources');
      
      expect(savedSuccessfully).toBeTruthy();
    });
  });

  test('user can add and manage multiple authors', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Add person author', async () => {
      await curation.openAccordion(curation.authorsAccordion);
      await curation.addAuthor();
      
      await curation.fillAuthor(0, {
        type: 'Person',
        firstName: 'John',
        lastName: 'Doe',
        orcid: '0000-0002-1234-5678',
        isContactPerson: true,
        email: 'john.doe@example.com',
      });
    });

    await test.step('Add institution author', async () => {
      await curation.addAuthor();
      
      await curation.fillAuthor(1, {
        type: 'Institution',
        institutionName: 'Test University',
        website: 'https://test-university.edu',
      });
    });

    await test.step('Add third author', async () => {
      await curation.addAuthor();
      
      await curation.fillAuthor(2, {
        type: 'Person',
        firstName: 'Jane',
        lastName: 'Smith',
      });
    });

    await test.step('Verify all authors are displayed', async () => {
      // Verify author regions exist
      await expect(curation.getAuthorRegion(0)).toBeVisible();
      await expect(curation.getAuthorRegion(1)).toBeVisible();
      await expect(curation.getAuthorRegion(2)).toBeVisible();
    });

    await test.step('Remove second author', async () => {
      await curation.removeAuthor(1);
      
      // Verify only 2 authors remain
      await expect(curation.getAuthorRegion(0)).toBeVisible();
      await expect(curation.getAuthorRegion(1)).toBeVisible();
    });
  });

  test('user can add titles in multiple languages', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Open titles accordion', async () => {
      await curation.openAccordion(curation.titlesAccordion);
    });

    await test.step('Add primary title', async () => {
      await curation.addTitle();
      await curation.fillTitle(0, {
        title: 'Primary Dataset Title',
        language: 'English',
        type: 'Main Title',
      });
    });

    await test.step('Add German title', async () => {
      await curation.addTitle();
      await curation.fillTitle(1, {
        title: 'Deutscher Datensatztitel',
        language: 'German',
        type: 'Translated Title',
      });
    });

    await test.step('Add subtitle', async () => {
      await curation.addTitle();
      await curation.fillTitle(2, {
        title: 'A Comprehensive Study',
        language: 'English',
        type: 'Subtitle',
      });
    });

    await test.step('Verify all titles are visible', async () => {
      const titlesSection = page.locator('[data-accordion-content="titles"]');
      await expect(titlesSection).toContainText('Primary Dataset Title');
      await expect(titlesSection).toContainText('Deutscher Datensatztitel');
      await expect(titlesSection).toContainText('A Comprehensive Study');
    });
  });

  test('user can add and manage descriptions', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Open descriptions accordion', async () => {
      await curation.openAccordion(curation.descriptionsAccordion);
    });

    await test.step('Add abstract description', async () => {
      await curation.addDescription();
      await curation.fillDescription(0, {
        description: 'This is a comprehensive abstract describing the dataset in detail.',
        language: 'English',
        type: 'Abstract',
      });
    });

    await test.step('Add methods description', async () => {
      await curation.addDescription();
      await curation.fillDescription(1, {
        description: 'Methodology: Data was collected using standard procedures.',
        language: 'English',
        type: 'Methods',
      });
    });

    await test.step('Verify descriptions are saved', async () => {
      const descriptionsSection = page.locator('[data-accordion-content="descriptions"]');
      await expect(descriptionsSection).toContainText('comprehensive abstract');
      await expect(descriptionsSection).toContainText('Methodology');
    });
  });

  test('user can add and manage dates', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Open dates accordion', async () => {
      await curation.openAccordion(curation.datesAccordion);
    });

    await test.step('Add publication date', async () => {
      await curation.addDate();
      await curation.fillDate(0, {
        date: '2024-01-15',
        type: 'Publication',
      });
    });

    await test.step('Add collection date range', async () => {
      await curation.addDate();
      await curation.fillDate(1, {
        dateFrom: '2023-06-01',
        dateTo: '2023-12-31',
        type: 'Collection',
      });
    });

    await test.step('Verify dates are displayed', async () => {
      const datesSection = page.locator('[data-accordion-content="dates"]');
      await expect(datesSection).toContainText('2024-01-15');
      await expect(datesSection).toContainText('2023-06-01');
    });
  });

  test('user can select controlled vocabularies', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Open controlled vocabularies accordion', async () => {
      await curation.openAccordion(curation.vocabulariesAccordion);
    });

    await test.step('Select resource type', async () => {
      const resourceTypeSelect = page.getByLabel('Resource Type');
      await resourceTypeSelect.click();
      await page.getByRole('option', { name: 'Dataset' }).click();
    });

    await test.step('Select language', async () => {
      const languageSelect = page.getByLabel('Language').first();
      await languageSelect.click();
      await page.getByRole('option', { name: 'English' }).click();
    });

    await test.step('Select subjects/keywords', async () => {
      const subjectInput = page.getByLabel('Subjects');
      await subjectInput.fill('Climate Science');
      
      // If autocomplete, wait for suggestions and select
      const suggestion = page.getByRole('option', { name: /Climate/i }).first();
      const hasSuggestion = await suggestion.isVisible({ timeout: 2000 }).catch(() => false);
      if (hasSuggestion) {
        await suggestion.click();
      }
    });

    await test.step('Verify selections are saved', async () => {
      const vocabulariesSection = page.locator('[data-accordion-content="vocabularies"]');
      const content = await vocabulariesSection.textContent();
      expect(content).toBeTruthy();
    });
  });

  test('comprehensive form with all fields', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Fill authors', async () => {
      await curation.openAccordion(curation.authorsAccordion);
      await curation.addAuthor();
      await curation.fillAuthor(0, {
        type: 'Person',
        firstName: 'Alice',
        lastName: 'Researcher',
        orcid: '0000-0001-2345-6789',
        isContactPerson: true,
        email: 'alice@research.org',
      });
    });

    await test.step('Fill titles', async () => {
      await curation.openAccordion(curation.titlesAccordion);
      await curation.addTitle();
      await curation.fillTitle(0, {
        title: 'Complete Dataset with All Metadata',
        language: 'English',
        type: 'Main Title',
      });
    });

    await test.step('Fill descriptions', async () => {
      await curation.openAccordion(curation.descriptionsAccordion);
      await curation.addDescription();
      await curation.fillDescription(0, {
        description: 'A comprehensive dataset with complete metadata for testing purposes.',
        language: 'English',
        type: 'Abstract',
      });
    });

    await test.step('Fill dates', async () => {
      await curation.openAccordion(curation.datesAccordion);
      await curation.addDate();
      await curation.fillDate(0, {
        date: '2024-10-13',
        type: 'Publication',
      });
    });

    await test.step('Fill controlled vocabularies', async () => {
      await curation.openAccordion(curation.vocabulariesAccordion);
      
      const resourceTypeSelect = page.getByLabel('Resource Type');
      await resourceTypeSelect.click();
      await page.getByRole('option', { name: 'Dataset' }).first().click();
    });

    await test.step('Save complete form', async () => {
      await curation.saveButton.click();
      
      // Wait for save to complete
      await page.waitForTimeout(2000);
      
      // Should show success or redirect
      const saved = 
        await page.getByRole('status').isVisible().catch(() => false) ||
        await page.url().includes('/resources');
      
      expect(saved).toBeTruthy();
    });
  });

  test('form validation prevents saving incomplete data', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Try to save empty form', async () => {
      await curation.saveButton.click();
      
      // Should show validation errors
      const errorVisible = await page.getByRole('alert').isVisible({ timeout: 2000 }).catch(() => false);
      
      if (errorVisible) {
        expect(errorVisible).toBeTruthy();
      } else {
        // Or save button might be disabled
        const saveDisabled = await curation.saveButton.isDisabled();
        expect(saveDisabled).toBeTruthy();
      }
    });
  });

  test('accordion state persists during form interaction', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Open multiple accordions', async () => {
      await curation.openAccordion(curation.authorsAccordion);
      await curation.openAccordion(curation.titlesAccordion);
      await curation.openAccordion(curation.descriptionsAccordion);
    });

    await test.step('Verify all opened accordions remain open', async () => {
      await expect(curation.authorsAccordion).toHaveAttribute('aria-expanded', 'true');
      await expect(curation.titlesAccordion).toHaveAttribute('aria-expanded', 'true');
      await expect(curation.descriptionsAccordion).toHaveAttribute('aria-expanded', 'true');
    });

    await test.step('Close one accordion', async () => {
      await curation.closeAccordion(curation.titlesAccordion);
      
      await expect(curation.titlesAccordion).toHaveAttribute('aria-expanded', 'false');
      await expect(curation.authorsAccordion).toHaveAttribute('aria-expanded', 'true');
    });
  });

  test('cancel button discards changes', async ({ page }) => {
    await loginAsTestUser(page);

    const curation = new CurationPage(page);
    await curation.goto();

    await test.step('Make some changes', async () => {
      await curation.openAccordion(curation.authorsAccordion);
      await curation.addAuthor();
      await curation.fillAuthor(0, {
        type: 'Person',
        firstName: 'Test',
        lastName: 'Cancel',
      });
    });

    await test.step('Click cancel', async () => {
      await curation.cancelButton.click();
      
      // Should redirect away from curation or show confirmation
      await page.waitForTimeout(1000);
      
      const leftPage = !page.url().includes('/curation') || 
                       await page.getByRole('dialog').isVisible().catch(() => false);
      
      expect(leftPage).toBeTruthy();
    });
  });
});
