import { test, expect } from '@playwright/test';

test('redirects to dashboard after valid login', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Email address').fill('test@example.com');
  await page.getByLabel('Password').fill('password');
  await page.getByRole('button', { name: 'Log in' }).click();

  await expect(page).toHaveURL(/\/dashboard/, { timeout: 15000 });
  await expect(page.getByText('Hello Test User!')).toBeVisible();
});
