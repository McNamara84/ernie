import { test, expect } from '@playwright/test';

test('shows an error for invalid login credentials', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Email address').fill('user@example.com');
  await page.getByLabel('Password').fill('wrong-password');
  await page.getByRole('button', { name: 'Log in' }).click();

  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
});
