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
  const titleInputs = page.getByRole('textbox', { name: 'Title' });
  await expect(titleInputs.first()).toBeVisible();
  await page.getByRole('button', { name: 'Add title' }).click();
  await expect(titleInputs).toHaveCount(2);
  await page.getByRole('combobox', { name: 'Title Type' }).nth(1).click();
  await expect(page.getByRole('option', { name: 'Main Title' })).toHaveCount(0);
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: 'Remove title' }).click();
  await expect(titleInputs).toHaveCount(1);
});

test('limits title rows to 100', async ({ page }) => {
  test.setTimeout(120_000);
  await page.goto('/login');
  await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
  await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
  await page.getByRole('button', { name: 'Log in' }).click();
  await page.waitForURL(/\/dashboard/);

  await page.goto('/curation');
  const addButton = page.getByRole('button', { name: 'Add title' });
  for (let i = 0; i < 99; i++) {
    if (!(await addButton.isEnabled())) break;
    await addButton.click();
  }
  const titleInputs = page.getByRole('textbox', { name: 'Title' });
  await expect(titleInputs).toHaveCount(100);
  await expect(addButton).toBeDisabled();
});
