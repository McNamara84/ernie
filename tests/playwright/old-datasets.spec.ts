import { expect,test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

test.describe('Old datasets overview', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();

    await page.waitForURL(/\/dashboard/, { timeout: 15_000 });
  });

  test('surfaces an accessible error state when the legacy source is unavailable', async ({ page }) => {
    await page.goto('/old-datasets');

    await expect(page).toHaveURL(/\/old-datasets$/);
    await expect(page.getByRole('heading', { name: 'Old Datasets' })).toBeVisible();

    const alert = page.getByRole('alert');
    await expect(alert).toContainText('Datenbankverbindung fehlgeschlagen');

    await expect(page.getByText('No datasets available. Please check the database connection.')).toBeVisible();
    await expect(page.getByText('Overview of legacy resources from the SUMARIOPMD database')).toBeVisible();

    await expect(page.getByRole('main')).toContainText('Old Datasets');
  });
});
