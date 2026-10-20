import { readFile } from 'node:fs/promises';

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
        await page.getByRole('combobox', { name: 'Period', exact: true }).selectOption('legacy');
        await expect(page).toHaveURL(/mode=legacy/);
        await expect(page.getByText(/These are not COUNTER metrics/)).toBeVisible();
        await expect(page.getByLabel('Landing page metric')).toHaveCount(0);
        await expect(page.getByLabel('Access method')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Export monthly CSV' })).toHaveCount(0);
    });

    for (const range of [
        { label: 'all months', access: 'regular', from: '', to: '' },
        { label: 'a bounded month range', access: 'machine', from: '2026-05', to: '2026-09' },
    ]) {
        test(`exports ${range.access} usage for ${range.label}`, async ({ page, browserName }, testInfo) => {
            await page.goto(`/statistics?access_method=${range.access}`);
            await expect(page.getByLabel('Access method')).toHaveValue(range.access);
            await page.getByLabel('From month').fill(range.from);
            await page.getByLabel('To month').fill(range.to);

            const exportButton = page.getByRole('button', { name: 'Export monthly CSV' });
            const filenamePattern = new RegExp(
                `^usage-${range.from || 'start'}-${range.to || 'latest'}-through-(?:pending|\\d{4}-\\d{2}-\\d{2})\\.csv$`,
            );
            let exportUrl: URL;
            let csv: string;

            if (browserName === 'webkit') {
                // Playwright's Linux WebKit renders attachment navigations instead
                // of emitting a download: https://github.com/microsoft/playwright/issues/34076.
                // Verify the real form response, including its download contract.
                const [response] = await Promise.all([
                    page.waitForResponse((result) => new URL(result.url()).pathname === '/statistics/usage.csv'),
                    exportButton.click(),
                ]);
                expect(response.status()).toBe(200);
                expect(response.headers()['content-type']).toBe('text/csv; charset=UTF-8');
                const disposition = response.headers()['content-disposition'];
                expect(disposition).toMatch(/^attachment; filename=/);
                expect(disposition.match(/filename="?([^";]+)"?/)?.[1]).toMatch(filenamePattern);
                exportUrl = new URL(response.url());
                csv = await response.text();
            } else {
                const [download] = await Promise.all([page.waitForEvent('download'), exportButton.click()]);
                expect(download.suggestedFilename()).toMatch(filenamePattern);
                expect(await download.failure()).toBeNull();
                exportUrl = new URL(download.url());
                // saveAs also copies downloads from a remote Linux browser.
                const filePath = testInfo.outputPath('usage.csv');
                await download.saveAs(filePath);
                csv = await readFile(filePath, 'utf8');
            }

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
