import { expect, test } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

async function login(page) {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/);
}

test('enables Save when required fields are filled', async ({ page }) => {
    await login(page);
    await page.goto('/curation');

    await page.getByLabel('Year').fill('2024');
    await page.getByRole('combobox', { name: 'Resource Type' }).click();
    await page.getByRole('option').first().click();
    await page.getByRole('combobox', { name: 'Language of Dataset' }).click();
    await page.getByRole('option', { name: 'English' }).click();
    await page.getByRole('textbox', { name: 'Title' }).fill('Main title');
    await page.getByRole('combobox', { name: 'License' }).click();
    await page.getByRole('option').first().click();

    const save = page.getByRole('button', { name: 'Save' });
    await expect(save).toBeEnabled();
    await expect(save).toHaveAttribute('aria-disabled', 'false');
});

test('keeps Save disabled when a required field is missing', async ({ page }) => {
    await login(page);
    await page.goto('/curation');

    await page.getByLabel('Year').fill('2024');
    await page.getByRole('combobox', { name: 'Resource Type' }).click();
    await page.getByRole('option').first().click();
    await page.getByRole('textbox', { name: 'Title' }).fill('Main title');
    await page.getByRole('combobox', { name: 'License' }).click();
    await page.getByRole('option').first().click();

    const save = page.getByRole('button', { name: 'Save' });
    await expect(save).toBeDisabled();
    await expect(save).toHaveAttribute('aria-disabled', 'true');
});
