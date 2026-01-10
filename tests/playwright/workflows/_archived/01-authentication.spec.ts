import { expect, test } from '@playwright/test';

import { INVALID_PASSWORD, TEST_USER_EMAIL, TEST_USER_GREETING, TEST_USER_PASSWORD } from '../constants';

// Authentication Tests
// Simple, focused tests for login and logout functionality.
// Based on working tests from main branch.

test.describe('Authentication', () => {
  test('redirects to dashboard after valid login', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);

    const loginButton = page.getByRole('button', { name: 'Log in' });
    await expect(loginButton).toBeEnabled({ timeout: 15000 });
    await loginButton.click();

    await page.waitForURL(/\/dashboard/, { timeout: 15000 });
    await expect(page.getByText(TEST_USER_GREETING)).toBeVisible();
  });

  test('shows an error for invalid login credentials', async ({ page }) => {
    await page.goto('/login');

    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(INVALID_PASSWORD);

    const loginButton = page.getByRole('button', { name: 'Log in' });
    await expect(loginButton).toBeEnabled({ timeout: 15000 });
    await loginButton.click();

    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
    await expect(page).toHaveURL(/\/login/);
  });

});
