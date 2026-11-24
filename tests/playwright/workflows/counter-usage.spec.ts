import { readFile } from 'node:fs/promises';

import { expect, type Page, test as guestTest } from '@playwright/test';

import { test } from '../fixtures/authenticated';
import { LandingPage } from '../helpers/page-objects/LandingPage';

async function expectPublicPortals(page: Page) {
    const navigation = page.getByRole('navigation', { name: 'Usage navigation' });
    await expect(navigation.getByRole('link', { name: 'Data Portal' })).toHaveAttribute('href', '/doi-search');
    await expect(navigation.getByRole('link', { name: 'IGSN Portal' })).toHaveAttribute('href', '/igsn-search');
    await expect(navigation.getByRole('link')).toHaveCount(2);
    await expect(page.locator('a[href="/login"], a[href="/dashboard"], a[href="/changelog"]')).toHaveCount(0);
}

test.describe('Usage statistics', () => {
    for (const fixture of [
        { path: '/10.1234/playwright-published/playwright-published', igsn: false },
        { path: '/10.60510/gfbno7002exz3001/playwright-legacy-igsn', igsn: true },
    ]) {
        guestTest(`returns guests to the originating ${fixture.igsn ? 'IGSN' : 'DOI'} landing page`, async ({ page, context }) => {
            await page.setViewportSize({ width: 390, height: 844 });
            await page.goto(fixture.path);
            const usage = page.locator('[aria-label="Usage"]');
            await expect(usage.getByRole('heading', { name: 'Usage', exact: true })).toBeVisible();
            if (fixture.igsn) {
                await expect(usage).not.toContainText(/downloads/i);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await usage.getByRole('link', { name: 'How we count', exact: true }).click();
            await expect(page).toHaveURL(/\/usage-statistics\?landing_page=[1-9]\d*$/);
            const methodUrl = page.url();
            await page.reload();
            const label = fixture.igsn ? 'Back to IGSN sample' : 'Back to resource';
            const returnLink = page.getByRole('link', { name: label });
            await expect(returnLink).toHaveAttribute('href', fixture.path);
            await returnLink.press('Enter');
            await expect(page).toHaveURL(fixture.path);

            const freshTab = await context.newPage();
            await freshTab.goto(methodUrl);
            await freshTab.getByRole('link', { name: label }).click();
            await expect(freshTab).toHaveURL(fixture.path);
        });
    }

    guestTest('shows guests the counting method and privacy policy on a narrow screen', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/usage-statistics');
        await expect(page.getByRole('heading', { name: 'How we count usage' })).toBeVisible();
        await expectPublicPortals(page);
        await expect(page.getByRole('link', { name: 'GFZ Data Services privacy policy' })).toHaveAttribute(
            'href',
            'https://gfz-data-services.gfz.de/about-us/data-protection',
        );
        await expect(page.getByText(/IGSNs describe physical samples and have views only/)).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    });

    guestTest('keeps a token-protected review open while the method opens in a new tab', async ({ page, context }) => {
        await new LandingPage(page).gotoPreview('playwright-review');
        const [method] = await Promise.all([
            context.waitForEvent('page'),
            page.getByRole('link', { name: 'How we count (opens in a new tab)' }).click(),
        ]);
        await expect(method).toHaveURL('/usage-statistics');
        await expectPublicPortals(method);
        expect(await method.evaluate(() => document.referrer)).toBe('');
        await method.close();
        await expect(page.getByText('Preview Mode', { exact: true })).toBeVisible();
    });

    for (const origin of ['/statistics', '/docs?tab=datasets&section=landing-pages']) {
        test(`shows public navigation when staff open the method from ${origin}`, async ({ page }) => {
            await page.goto(origin);
            await page.getByRole('link', { name: 'How we count', exact: true }).click();
            await expect(page).toHaveURL('/usage-statistics');
            await expectPublicPortals(page);
        });
    }

    for (const width of [390, 1440]) {
        test(`preserves filters and separates the historical dashboard at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 1000 });
            await page.goto('/statistics');
            await expect(page.getByRole('heading', { name: 'Public engagement statistics' })).toBeVisible();
            const metric = page.getByRole('combobox', { name: 'Landing page metric' });
            await metric.focus();
            await metric.press('Enter');
            await expect(page.getByRole('option', { name: 'Total', exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(metric).toBeFocused();
            await metric.press('Enter');
            await expect(page.getByRole('option', { name: 'Total', exact: true })).toBeVisible();
            await page.keyboard.press('End');
            await expect(page.getByRole('option', { name: 'Total', exact: true })).toBeFocused();
            await page.keyboard.press('Enter');
            await expect(page).toHaveURL(/metric=total/);
            await page.getByRole('combobox', { name: 'Access method' }).click();
            await page.getByRole('option', { name: 'Machine', exact: true }).click();
            await expect(page).toHaveURL(/access_method=machine/);
            await page.reload();
            await expect(metric).toHaveText('Total');
            await expect(page.getByRole('combobox', { name: 'Access method' })).toHaveText('Machine');
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
            await page.getByRole('combobox', { name: 'Period', exact: true }).click();
            await page.getByRole('option', { name: 'Before COUNTER', exact: true }).click();
            await expect(page).toHaveURL(/mode=legacy/);
            await expect(page.getByText(/These are not COUNTER metrics/)).toBeVisible();
            await expect(metric).toHaveCount(0);
            await expect(page.getByLabel('Access method')).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Export monthly CSV' })).toHaveCount(0);
            await page.getByRole('combobox', { name: 'Period', exact: true }).click();
            await page.getByRole('option', { name: 'COUNTER', exact: true }).click();
            await expect(page).toHaveURL(/mode=counter/);
            await expect(metric).toHaveText('Total');
            await expect(page.getByRole('combobox', { name: 'Access method' })).toHaveText('Machine');
            await expect(page.getByRole('button', { name: 'Export monthly CSV' })).toBeVisible();
        });
    }

    for (const range of [
        { label: 'all months', access: 'regular', from: '', to: '' },
        { label: 'a bounded month range', access: 'machine', from: '2026-05', to: '2026-09' },
    ]) {
        test(`exports ${range.access} usage for ${range.label}`, async ({ page }, testInfo) => {
            await page.goto(`/statistics?access_method=${range.access}`);
            await expect(page.getByRole('combobox', { name: 'Access method' })).toHaveText(range.access === 'machine' ? 'Machine' : 'Regular');
            await page.getByLabel('From month').fill(range.from);
            await page.getByLabel('To month').fill(range.to);

            const exportButton = page.getByRole('button', { name: 'Export monthly CSV' });
            const filenamePattern = new RegExp(
                `^usage-${range.from || 'start'}-${range.to || 'latest'}-through-(?:pending|\\d{4}-\\d{2}-\\d{2})\\.csv$`,
            );
            const [download] = await Promise.all([page.waitForEvent('download'), exportButton.click()]);
            expect(download.suggestedFilename()).toMatch(filenamePattern);
            expect(await download.failure()).toBeNull();
            const exportUrl = new URL(download.url());
            // saveAs also copies downloads from a remote Linux browser.
            const filePath = testInfo.outputPath('usage.csv');
            await download.saveAs(filePath);
            const csv = await readFile(filePath, 'utf8');

            expect(exportUrl.pathname).toBe('/statistics/usage.csv');
            expect(Object.fromEntries(exportUrl.searchParams)).toEqual({
                access_method: range.access,
                from: range.from,
                to: range.to,
            });
            expect(csv.split(/\r?\n/)[0].split(',')).toEqual([
                'landing_page_id',
                'identifier',
                'resource_type',
                'month',
                'access_method',
                'total_investigations',
                'unique_investigations',
                'total_requests',
                'unique_requests',
            ]);
        });
    }
});
