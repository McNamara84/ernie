import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Curation Form Basic Tests
// Critical curation functionality is covered by xml-upload tests.
// These tests just verify basic form accessibility.

test.describe('Curation Form', () => {
  test('curation page requires authentication', async ({ page }) => {
    // Try to access curation without login
    await page.goto('/curation');
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('curation page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to curation
    await page.goto('/curation');
    
    // Should be accessible (even if empty without XML upload)
    await expect(page).toHaveURL(/\/curation/);
  });
});
