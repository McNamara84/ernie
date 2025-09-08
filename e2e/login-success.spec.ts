import { test, expect } from '@playwright/test';
import {
  TEST_USER_EMAIL,
  TEST_USER_PASSWORD,
  TEST_USER_GREETING,
} from './constants';

test('redirects to dashboard after valid login', async ({ page }) => {
  await page.goto('/login');

  await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();

  await page.waitForURL(/\/dashboard/, { timeout: 15000 });
  await expect(page.getByText(TEST_USER_GREETING)).toBeVisible();
});
