import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { navigateWithTlsRetry } from '../helpers/navigation';
import { LandingPage } from '../helpers/page-objects/LandingPage';

// Seed PlaywrightTestSeeder before running this slice; it includes the tombstone fixture.
// The fixture represents a completed sync; these tests never call DataCite.
async function signIn(page: Page) {
    const login = await page.request.get('/login');
    const token = (await login.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
    expect(token).toBeTruthy();
    const response = await page.request.post('/login', {
        form: { _token: token!, email: TEST_USER_EMAIL, password: TEST_USER_PASSWORD },
        maxRedirects: 0,
    });
    expect(response.status()).toBe(302);
}

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
    await signIn(page);
    await navigateWithTlsRetry(page, '/resources?status=dead&search=playwright-tombstone', { waitUntil: 'commit' });
    const row = page.getByTestId('resources-table').getByRole('row').filter({ hasText: 'Playwright: Tombstone Resource' });
    await expect(row).toBeVisible({ timeout: 90_000 });
    await expect(row.getByText('Dead', { exact: true })).toBeVisible();
    await row.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Set up landing page', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('DataCite sync: completed.', { exact: false })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Update', exact: true })).toBeDisabled();
    await dialog.getByRole('combobox', { name: 'Reason' }).click();
    await page.getByRole('option', { name: 'Resource retracted' }).click();
    await dialog.getByLabel('Public explanation').fill('Unsaved browser preview explanation.');
    const previewPromise = page.waitForEvent('popup');
    await dialog.getByRole('button', { name: 'Preview tombstone page' }).click();
    const preview = await previewPromise;
    await expect(preview.getByText('Resource retracted', { exact: true })).toBeVisible();
    await expect(preview.getByText('Unsaved browser preview explanation.', { exact: true })).toBeVisible();
    await expect(preview.getByText('Tombstone preview', { exact: false })).toBeVisible();
    await expect(preview.getByTestId('data-request-section')).toHaveCount(0);
    await preview.close();
});

test('the editor opens the public tombstone directly and keeps its DOI read-only', async ({ page }) => {
    test.setTimeout(180_000);
    await signIn(page);
    await navigateWithTlsRetry(page, '/resources?status=dead&search=playwright-tombstone', { waitUntil: 'commit' });
    const row = page.getByTestId('resources-table').getByRole('row').filter({ hasText: 'Playwright: Tombstone Resource' });
    await expect(row).toBeVisible({ timeout: 90_000 });
    const editorPromise = page.waitForEvent('popup');
    await row.getByText('Playwright: Tombstone Resource', { exact: true }).click();
    const editor = await editorPromise;
    const showLandingPage = editor.getByTestId('show-lp-preview-button');
    await expect(showLandingPage).toHaveText('Show LP', { timeout: 90_000 });
    await expect(editor.getByLabel('DOI', { exact: true })).toHaveAttribute('readonly');
    await expect(editor.getByTestId('save-draft-button')).toHaveCount(0);
    const landingPagePromise = editor.waitForEvent('popup');
    await showLandingPage.click();
    const landingPage = await landingPagePromise;
    await expect(landingPage.getByRole('heading', { name: 'This resource is no longer available' })).toBeVisible();
    expect(new URL(landingPage.url()).searchParams.has('preview')).toBe(false);
    await expect(landingPage.getByText('The original files were permanently lost.', { exact: false })).toBeVisible();
    await landingPage.close();
    await editor.close();
});
