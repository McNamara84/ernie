import { expect, test } from '@playwright/test';
import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from './constants';

test('user can add and remove title rows', async ({ page }) => {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL(/\/dashboard/);

    await page.goto('/curation');
    const titleInputs = page.getByRole('textbox', { name: /Title/ });
    await expect(titleInputs.first()).toBeVisible();
    await titleInputs.first().fill('First title');
    const addButton = page.getByRole('button', { name: 'Add title' });
    await addButton.click();
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
    const titleInputs = page.getByRole('textbox', { name: /Title/ });
    for (let i = 0; i < 99; i++) {
        await titleInputs.nth(i).fill(`Title ${i + 1}`);
        await addButton.click();
    }
    await expect(titleInputs).toHaveCount(100);
    await titleInputs.last().fill('Title 100');
    await expect(addButton).toBeDisabled();
});
