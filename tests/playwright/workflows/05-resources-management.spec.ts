import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Resources Management Basic Tests
// Verifies resources page is accessible.

test.describe('Resources Management', () => {
  test('resources page requires authentication', async ({ page }) => {
    // Try to access resources without login
    await page.goto('/resources', { waitUntil: 'networkidle' });
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('resources page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login', { waitUntil: 'networkidle' });
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to resources
    await page.goto('/resources', { waitUntil: 'networkidle' });
    
    // Should be accessible
    await expect(page).toHaveURL(/\/resources/);
  });
});
