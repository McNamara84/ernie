import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Old Datasets Basic Tests
// Verifies old datasets page is accessible.

test.describe('Old Datasets', () => {
  test('old datasets page requires authentication', async ({ page }) => {
    // Try to access without login
    await page.goto('/old-datasets', { waitUntil: 'domcontentloaded' });
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('old datasets page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to old datasets
    await page.goto('/old-datasets', { waitUntil: 'domcontentloaded', timeout: 60000 });
    
    // Should be accessible
    await expect(page).toHaveURL(/\/old-datasets/);
  });
});
