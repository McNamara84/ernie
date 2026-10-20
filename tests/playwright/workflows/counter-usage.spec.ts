import { expect } from '@playwright/test';

import { test } from '../fixtures/authenticated';

test.describe('Usage statistics', () => {
    for (const fixture of [
        { path: '/10.1234/playwright-published/playwright-published', igsn: false },
        { path: '/10.60510/gfbno7002exz3001/playwright-legacy-igsn', igsn: true },
    ]) {
        test(`shows the ${fixture.igsn ? 'IGSN' : 'DOI'} usage module and method link`, async ({ page }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            await page.goto(fixture.path);
            const usage = page.locator('[aria-label="Usage"]');
            await expect(usage.getByRole('heading', { name: 'Usage', exact: true })).toBeVisible();
            await expect(usage.getByRole('link', { name: 'How we count', exact: true })).toHaveAttribute('href', '/usage-statistics');
            if (fixture.igsn) {
                await expect(usage).not.toContainText(/downloads/i);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        });
    }

    test('explains the counting method and links the existing privacy policy on a narrow screen', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/usage-statistics');
        await expect(page.getByRole('heading', { name: 'How we count usage' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'GFZ Data Services privacy policy' })).toHaveAttribute(
            'href',
            'https://dataservices.gfz-potsdam.de/web/about-us/data-protection',
        );
        await expect(page.getByText(/IGSNs describe physical samples and have views only/)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });

    test('preserves filters in the URL and separates the historical dashboard', async ({ page }) => {
        await page.goto('/statistics');
        await expect(page.getByRole('heading', { name: 'Public engagement statistics' })).toBeVisible();
        await page.getByLabel('Landing page metric').selectOption('total');
        await expect(page).toHaveURL(/metric=total/);
        await page.getByLabel('Access method').selectOption('machine');
        await expect(page).toHaveURL(/access_method=machine/);
        await page.reload();
        await expect(page.getByLabel('Landing page metric')).toHaveValue('total');
        await expect(page.getByLabel('Access method')).toHaveValue('machine');
        const download = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Export monthly CSV' }).click();
        expect((await download).suggestedFilename()).toMatch(/^usage-start-latest-through-.*\.csv$/);
        await page.getByRole('combobox', { name: 'Period', exact: true }).selectOption('legacy');
        await expect(page).toHaveURL(/mode=legacy/);
        await expect(page.getByText(/These are not COUNTER metrics/)).toBeVisible();
        await expect(page.getByLabel('Landing page metric')).toHaveCount(0);
        await expect(page.getByLabel('Access method')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Export monthly CSV' })).toHaveCount(0);
    });
});
