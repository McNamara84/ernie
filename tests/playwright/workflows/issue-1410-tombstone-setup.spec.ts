import { expect, type Page } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { test } from '../fixtures/client-identity';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

const doi = '10.1234/playwright-published';
const state = {
    can_manage: true,
    is_tombstone: false,
    revision: 0,
    reason: null as string | null,
    statement: null as string | null,
    reasons: [{ value: 'data_lost', label: 'Data lost' }],
    sync: null,
    restore: null,
};

async function navigate(page: Page, path: string) {
    try {
        await page.goto(path, { waitUntil: 'domcontentloaded' });
    } catch (error) {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) throw error;
        await page.goto(path, { waitUntil: 'domcontentloaded' });
    }
}

async function openResourceSetup(page: Page) {
    await navigate(page, '/resources');
    const resources = new ResourcesPage(page);
    await resources.search(doi);
    const row = resources.resourceTable.locator('tbody tr').filter({ hasText: doi }).first();
    await expect(row).toBeVisible();
    await row.getByRole('checkbox').click();
    const setup = page.getByTestId('resources-action-setup-landing-page');
    if (!(await setup.isVisible())) await page.getByTestId('resources-actions-menu-trigger').click();
    await setup.click();
    return page.getByRole('dialog');
}

test.beforeEach(async ({ page }) => {
    test.setTimeout(120_000);
    page.setDefaultNavigationTimeout(60_000);
    await navigate(page, '/login');
    await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
    await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page).toHaveURL(/\/dashboard/, { timeout: 60_000 });
});

for (const width of [1280, 390]) {
    test(`offers a verified tombstone last and collapsed with keyboard access at width ${width}`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.route('**/landing-page/tombstone?*', (route) =>
            route.fulfill({
                json: { tombstone: state, activation_eligibility: { status: 'eligible', reason: null } },
            }),
        );
        const dialog = await openResourceSetup(page);
        const toggle = dialog.getByRole('button', { name: 'Tombstone page', exact: true });
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(dialog.getByLabel('Public explanation')).toHaveCount(0);
        expect(
            await toggle.evaluate(
                (element) => element.closest('section') === document.querySelector('[data-testid="setup-lp-modal-scroll-area"]')?.lastElementChild,
            ),
        ).toBe(true);
        await toggle.focus();
        await page.keyboard.press('Enter');
        await expect(dialog.getByLabel('Public explanation')).toBeVisible();
        await dialog.getByLabel('Public explanation').fill('An unsaved explanation.');
        await toggle.focus();
        await page.keyboard.press('Space');
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await page.keyboard.press('Enter');
        await expect(dialog.getByLabel('Public explanation')).toHaveValue('An unsaved explanation.');
        await expect(dialog.getByTestId('setup-lp-modal-footer').getByRole('button', { name: 'Cancel' })).toBeVisible();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
    });
}

test('automatically expands active tombstones outside the disabled normal form', async ({ page }) => {
    await page.route('**/landing-page/tombstone?*', (route) =>
        route.fulfill({
            json: { tombstone: { ...state, is_tombstone: true, revision: 1, statement: 'The data were lost.' } },
        }),
    );
    await page.route(/\/resources\/\d+\/landing-page$/, async (route) => {
        const response = await route.fetch();
        const payload = await response.json();
        payload.landing_page.is_tombstone = true;
        payload.landing_page.tombstone_revision = 1;
        await route.fulfill({ response, json: payload });
    });
    const dialog = await openResourceSetup(page);
    await expect(dialog.getByRole('button', { name: 'Tombstone page', exact: true })).toHaveAttribute('aria-expanded', 'true');
    await expect(dialog.getByLabel('Public explanation')).toBeEnabled();
    await expect(dialog.getByTestId('setup-lp-modal-editable-fields')).toHaveAttribute('disabled', '');
    await expect(dialog.getByLabel('Landing Page Template')).toBeDisabled();
    await expect(dialog.getByRole('button', { name: 'Restore landing page' })).toBeVisible();
});

test('keeps normal setup available when registration verification is unavailable', async ({ page }) => {
    await page.route('**/landing-page/tombstone?*', (route) =>
        route.fulfill({
            json: { tombstone: state, activation_eligibility: { status: 'unavailable', reason: 'verification_unavailable' } },
        }),
    );
    const dialog = await openResourceSetup(page);
    await expect(dialog.getByRole('button', { name: 'Retry registration check' })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Tombstone page', exact: true })).toHaveCount(0);
    await expect(dialog.getByRole('button', { name: 'Update', exact: true })).toBeEnabled();
});

test('editor Preview LP never requests tombstone settings even with a DOI', async ({ page, browserName }) => {
    let tombstoneRequests = 0;
    await page.route('**/landing-page/tombstone*', (route) => {
        tombstoneRequests += 1;
        return route.fulfill({ json: { tombstone: state, activation_eligibility: { status: 'eligible', reason: null } } });
    });
    await navigate(page, '/editor');
    await page.getByTestId('main-title-input').fill(`Issue 1410 preview ${Date.now()}`);
    const doiInput = page.getByLabel('DOI', { exact: true });
    await doiInput.fill(`10.83279/issue-1410-${browserName}-${Date.now()}`);
    const validation = page.waitForResponse((response) => response.url().endsWith('/api/v1/doi/validate') && response.request().method() === 'POST');
    await doiInput.blur();
    expect(await (await validation).json()).toMatchObject({ exists: false, is_valid_format: true });
    await expect(doiInput).toBeEnabled();
    const preview = page.getByTestId('show-lp-preview-button');
    await preview.focus();
    await preview.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Setup Landing Page' });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Landing Page Template')).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Tombstone page', exact: true })).toHaveCount(0);
    expect(tombstoneRequests).toBe(0);
});
