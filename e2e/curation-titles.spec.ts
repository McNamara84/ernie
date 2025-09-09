import { test, expect } from '@playwright/test';
import {
  TEST_USER_EMAIL,
  TEST_USER_PASSWORD,
} from './constants';

test('user can add and remove title rows', async ({ page }) => {
  await page.goto('/login');
  await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL(/\/dashboard/);

  await page.goto('/curation');
  await expect(page.getByLabel('Title').first()).toBeVisible();
  await page.getByRole('button', { name: 'Add title' }).click();
  await expect(page.getByLabel('Title')).toHaveCount(2);
  await page.getByLabel('Title Type').nth(1).click();
  await expect(page.getByRole('option', { name: 'Main Title' })).toHaveCount(0);
  await page.getByRole('button', { name: 'Remove title' }).click();
  await expect(page.getByLabel('Title')).toHaveCount(1);
});
