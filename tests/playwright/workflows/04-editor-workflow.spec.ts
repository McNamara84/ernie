import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

// Editor Form Basic Tests
// Critical editor functionality is covered by xml-upload tests.
// These tests just verify basic form accessibility.

test.describe('Editor Form', () => {
  test('editor page requires authentication', async ({ page }) => {
    // Try to access editor without login
    await page.goto('/editor', { waitUntil: 'networkidle' });
    
    // Should redirect to login
    await expect(page).toHaveURL(/\/login/);
  });

  test('editor page is accessible after login', async ({ page }) => {
    // Login first
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    
    // Navigate to editor
    await page.goto('/editor', { waitUntil: 'networkidle' });
    
    // Should be accessible (even if empty without XML upload)
    await expect(page).toHaveURL(/\/editor/);
  });
});
