import { expect,test } from '@playwright/test';

import { INVALID_PASSWORD,TEST_USER_EMAIL } from './constants';

test('shows an error for invalid login credentials', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(INVALID_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();

  await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
  await expect(page).toHaveURL(/\/login/);
});
