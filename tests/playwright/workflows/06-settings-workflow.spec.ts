import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Settings Basic Tests
// Verifies settings page is accessible and basic functionality works.

test.describe('Settings', () => {
  test('settings page requires authentication', async ({ page }) => {
    // Try to access settings without login
    await page.goto('/settings');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('settings page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to settings
    await page.goto('/settings');
    
    // Should be accessible
    await expect(page).toHaveURL(/\/settings/);
  });
});
