import { test, expect } from '@playwright/test';
import { TEST_USER_EMAIL, INVALID_PASSWORD } from './constants';

test('shows an error for invalid login credentials', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(INVALID_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();

  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
});
