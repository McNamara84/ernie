import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

test('opens Tools contacts, copies an address and follows both exact contact searches', async ({ page, context, browserName }) => {
    await loginAsTestUser(page);
    if (browserName === 'chromium') await context.grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.getByRole('link', { name: 'Contact Persons', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Contact Persons' })).toBeVisible();
    await page.getByRole('searchbox', { name: 'Search contact persons' }).fill('contact1352@example.test');
    await expect(page).toHaveURL(/search=contact1352%40example\.test/);
    const row = page.getByRole('row').filter({ hasText: 'PlaywrightContact1352, Ada' });
    await expect(row).toHaveCount(1);
    await row.getByRole('button', { name: 'Copy email contact1352@example.test' }).click();
    await expect(page.getByText('Email address copied')).toBeVisible();
    if (browserName === 'chromium') expect(await page.evaluate(() => navigator.clipboard.readText())).toBe('contact1352@example.test');
    await row.getByRole('link', { name: /View 1 resources/ }).click();
    await expect(page).toHaveURL(/\/resources\?contact=[a-f0-9]{64}$/);
    await expect(page.getByText('Contact: PlaywrightContact1352, Ada · contact1352@example.test', { exact: true })).toBeVisible();
    await expect(page.getByText('Playwright: Contact 1352 dataset', { exact: true })).toBeVisible();
    await page.goBack();
    await expect(row).toHaveCount(1);
    await row.getByRole('link', { name: /View 1 IGSNs/ }).click();
    await expect(page).toHaveURL(/\/igsns\?contact=[a-f0-9]{64}$/);
    await expect(page.getByText('Playwright: Contact 1352 physical-object', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Remove contact filter' }).click();
    await expect(page).not.toHaveURL(/contact=/);
});

test('keeps contact controls accessible on a narrow screen', async ({ page }) => {
    await loginAsTestUser(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/contact-persons?search=contact1352');
    const search = page.getByRole('searchbox', { name: 'Search contact persons' });
    await search.focus();
    await expect(search).toBeFocused();
    await expect(page.getByRole('button', { name: 'Copy email contact1352@example.test' })).toBeVisible();
    const result = await new AxeBuilder({ page }).include('section[aria-label="Contact person overview"]').analyze();
    expect(result.violations).toEqual([]);
});

test('preserves newer input across a delayed search response and synchronizes browser history', async ({ page }) => {
    await loginAsTestUser(page);
    await page.goto('/contact-persons');
    await page.clock.pauseAt(new Date());
    const responseReady = Promise.withResolvers<void>();
    const releaseResponse = Promise.withResolvers<void>();
    await page.route('**/contact-persons?**', async (route) => {
        if (new URL(route.request().url()).searchParams.get('search') !== 'contact1352') {
            await route.continue();
            return;
        }
        const response = await route.fetch();
        responseReady.resolve();
        await releaseResponse.promise;
        await route.fulfill({ response });
    });

    try {
        const search = page.getByRole('searchbox', { name: 'Search contact persons' });
        await search.fill('contact1352');
        await page.clock.runFor(400);
        await responseReady.promise;
        await search.fill('contact1352@example.test');
        await page.clock.runFor(200);
        releaseResponse.resolve();
        await expect(page).toHaveURL((url) => url.searchParams.get('search') === 'contact1352');
        await expect(search).toHaveValue('contact1352@example.test');
        await page.clock.runFor(200);
        await expect(page).toHaveURL(/search=contact1352%40example\.test/);
        await expect(page.getByRole('row').filter({ hasText: 'PlaywrightContact1352, Ada' })).toHaveCount(1);

        // Inertia uses Date.now() for history remount keys; avoid frozen, identical keys.
        await page.clock.runFor(1);
        await page.goBack();
        await expect(search).toHaveValue('contact1352');
        await search.fill('Pending');
        await page.clock.runFor(1);
        await page.goBack();
        await expect(search).toHaveValue('');
        await page.clock.runFor(400);
        await expect(page).toHaveURL(/\/contact-persons$/);
        await page.clock.runFor(1);
        await page.goForward();
        await expect(search).toHaveValue('contact1352');
    } finally {
        releaseResponse.resolve();
    }
});
