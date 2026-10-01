import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { LandingPage } from '../helpers/page-objects/LandingPage';

// Seed PlaywrightTestSeeder and TombstonePlaywrightSeeder before running this slice.
// The fixture represents a completed sync; these tests never call DataCite.
test('public tombstone keeps citation, metadata and contact without offering data access', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('playwright-tombstone');
    await landingPage.verifyPageLoaded();
    await expect(page.getByRole('heading', { name: 'This resource is no longer available' })).toBeVisible();
    await expect(page.getByText('The original files were permanently lost.', { exact: false })).toBeVisible();
    await expect(landingPage.heroCitation).toContainText('Playwright: Tombstone Resource');
    await expect(landingPage.heroCitation).toContainText('https://doi.org/10.1234/playwright-tombstone');
    await expect(page.getByRole('heading', { name: 'Contact Information' })).toBeVisible();
    await expect(page.getByTestId('files-section')).toHaveCount(0);
    await expect(page.getByTestId('data-request-section')).toHaveCount(0);
    const jsonLd = await page.locator('script[type="application/ld+json"]').first().textContent();
    expect(JSON.parse(jsonLd!)).toMatchObject({
        conditionsOfAccess: 'This resource is no longer available.',
        identifier: { value: 'doi:10.1234/playwright-tombstone' },
    });
    expect(JSON.parse(jsonLd!)).not.toHaveProperty('distribution');
    await expect(page.locator('link[rel="item"]')).toHaveCount(0);
});

test('tombstone notice remains accessible on a narrow screen', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await new LandingPage(page).goto('playwright-tombstone');
    await expect(page.getByRole('heading', { name: 'This resource is no longer available' })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true);
    const accessibility = await new AxeBuilder({ page }).include('section[aria-labelledby="tombstone-notice-heading"]').analyze();
    expect(accessibility.violations).toEqual([]);
});

test('Dead filtering opens saved tombstone settings and previews an unsaved explanation', async ({ page }) => {
    test.setTimeout(180_000);
    const login = await page.request.get('/login');
    const token = (await login.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
    expect(token).toBeTruthy();
    const signIn = await page.request.post('/login', {
        form: { _token: token!, email: TEST_USER_EMAIL, password: TEST_USER_PASSWORD },
        maxRedirects: 0,
    });
    expect(signIn.status()).toBe(302);
    await page.goto('/resources?status=dead&search=playwright-tombstone', { waitUntil: 'commit' });
    const row = page.getByTestId('resources-table').getByRole('row').filter({ hasText: 'Playwright: Tombstone Resource' });
    await expect(row).toBeVisible({ timeout: 90_000 });
    await expect(row.getByText('Dead', { exact: true })).toBeVisible();
    await row.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Set up landing page', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('DataCite sync: completed.', { exact: false })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Update', exact: true })).toBeDisabled();
    await dialog.getByLabel('Public explanation').fill('Unsaved browser preview explanation.');
    const previewPromise = page.waitForEvent('popup');
    await dialog.getByRole('button', { name: 'Preview tombstone page' }).click();
    const preview = await previewPromise;
    await expect(preview.getByText('Unsaved browser preview explanation.', { exact: true })).toBeVisible();
    await expect(preview.getByText('Tombstone preview', { exact: false })).toBeVisible();
    await expect(preview.getByTestId('data-request-section')).toHaveCount(0);
    await preview.close();
});
