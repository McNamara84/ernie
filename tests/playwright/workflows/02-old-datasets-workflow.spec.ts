import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Old Datasets Basic Tests
// Verifies old datasets page is accessible.
// Note: These tests require the legacy VPN database connection (db_old).
// They are skipped by default when the database is not available.

test.describe('Old Datasets', () => {
  test('old datasets page requires authentication', async ({ page }) => {
    // Try to access without login
    await page.goto('/old-datasets');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test.skip('old datasets page is accessible after login', async ({ page }) => {
    // Skip: This test requires the legacy VPN database (db_old connection)
    // which is not available in the Docker development environment.
    // To run this test, ensure VPN connection to the legacy database.
    
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to old datasets
    await page.goto('/old-datasets');
    
    // Should be accessible
    await expect(page).toHaveURL(/\/old-datasets/);
  });
});
