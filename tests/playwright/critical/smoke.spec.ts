import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { DashboardPage, LoginPage } from '../helpers/page-objects';

/**
 * Critical Smoke Tests
 * 
 * Diese Tests prüfen die wichtigsten Funktionen der Anwendung.
 * Sie laufen vor allen anderen Tests und stoppen die Pipeline bei Fehlern.
 * 
 * Ziel: Schnelles Feedback (< 2 Minuten)
 */

test.describe('Critical Smoke Tests', () => {
  test('user can login and access dashboard', async ({ page }) => {
    const loginPage = new LoginPage(page);
    const dashboard = new DashboardPage(page);

    // Navigate to login
    await loginPage.goto();
    await loginPage.verifyOnLoginPage();

    // Perform login
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);

    // Verify dashboard is accessible
    await dashboard.verifyOnDashboard();
    await dashboard.verifyNavigationVisible();
  });

  test('main navigation works', async ({ page }) => {
    const loginPage = new LoginPage(page);
    const dashboard = new DashboardPage(page);

    // Login
    await loginPage.goto();
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);

    // Test navigation to key pages
    await dashboard.navigateTo('Old Datasets');
    await expect(page).toHaveURL(/\/old-datasets/);
    await expect(page.getByRole('heading', { name: 'Old Datasets' })).toBeVisible();

    await dashboard.navigateTo('Curation');
    await expect(page).toHaveURL(/\/curation/);

    await dashboard.navigateTo('Resources');
    await expect(page).toHaveURL(/\/resources/);
    await expect(page.getByRole('heading', { name: 'Resources' })).toBeVisible();
  });

  test('user can create a minimal resource', async ({ page }) => {
    const loginPage = new LoginPage(page);

    // Login
    await loginPage.goto();
    await loginPage.loginAndWaitForDashboard(TEST_USER_EMAIL, TEST_USER_PASSWORD);

    // Navigate to curation
    await page.goto('/curation');
    // Wait for Inertia.js/React hydration in CI
    await page.waitForLoadState('networkidle');

    // Fill minimal required fields
    await test.step('Fill required metadata', async () => {
      // DOI (required)
      const doiInput = page.getByLabel('DOI', { exact: true });
      await expect(doiInput).toBeVisible({ timeout: 30000 });
      await doiInput.fill('10.5555/smoke-test-' + Date.now());

      // Publication Year (required) - wait for it explicitly
      const yearInput = page.getByLabel('Publication Year');
      await expect(yearInput).toBeVisible({ timeout: 30000 });
      await yearInput.fill('2024');

      // Resource Type (required)
      const resourceTypeButton = page.getByRole('button', { name: /Select Resource Type/i });
      await resourceTypeButton.click();
      const datasetOption = page.getByRole('option', { name: 'Dataset' });
      await datasetOption.click();

      // Language (required)
      const languageButton = page.getByRole('button', { name: /Select Language/i });
      await languageButton.click();
      const englishOption = page.getByRole('option', { name: /English/i });
      await englishOption.click();
    });

    await test.step('Add required author', async () => {
      // Open Authors accordion
      const authorsAccordion = page.getByRole('button', { name: 'Authors' });
      const isExpanded = await authorsAccordion.getAttribute('aria-expanded');
      if (isExpanded === 'false') {
        await authorsAccordion.click();
      }

      // Fill first author
      const authorLastName = page.getByLabel('Last name').first();
      await authorLastName.fill('Smoke');
      
      const authorFirstName = page.getByLabel('First name').first();
      await authorFirstName.fill('Test');
    });

    await test.step('Add required title', async () => {
      // Open Titles accordion
      const titlesAccordion = page.getByRole('button', { name: 'Titles' });
      const isExpanded = await titlesAccordion.getAttribute('aria-expanded');
      if (isExpanded === 'false') {
        await titlesAccordion.click();
      }

      // Fill main title
      const titleInput = page.getByLabel('Title').first();
      await titleInput.fill('Smoke Test Resource');
    });

    // Note: We're NOT saving in smoke tests to avoid DB pollution
    // Just verify the form is functional
    await expect(page.getByRole('button', { name: /Save|Submit/i })).toBeVisible();
  });

  test('application handles errors gracefully', async ({ page }) => {
    const loginPage = new LoginPage(page);

    // Test invalid login
    await loginPage.goto();
    await loginPage.login('invalid@example.com', 'wrongpassword');

    // Should show error message
    await loginPage.verifyErrorDisplayed();

    // Page should still be functional
    await expect(loginPage.emailInput).toBeVisible();
    await expect(loginPage.passwordInput).toBeVisible();
    await expect(loginPage.loginButton).toBeVisible();
  });
});
