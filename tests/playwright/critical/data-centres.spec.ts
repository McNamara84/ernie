import { expect, test } from '@playwright/test';

import { dataCentres, openDataCentres, useDataCentreCatalogue } from '../helpers/data-centres';
import { openFind } from '../helpers/find';

test.describe('Data Centres', () => {
    for (const width of [320, 768, 1024, 1440]) {
        test(`renders every production centre with working logos and exact search links at ${width}px`, async ({ page }, testInfo) => {
            await page.setViewportSize({ width, height: 900 });
            await useDataCentreCatalogue(page);
            expect((await openDataCentres(page))?.status()).toBe(200);
            await expect(page).toHaveTitle(/^Data Centres(?:\s|$)/);
            await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', 'https://dataservices.gfz.de/data-centres');
            const cells = page.getByRole('list', { name: 'Data centres' }).getByRole('listitem');
            await expect(cells).toHaveCount(dataCentres.length);
            for (const [index, centre] of dataCentres.entries()) {
                const cell = cells.nth(index);
                const link = cell.getByRole('link', { name: /^View publications:/ });
                const url = new URL((await link.getAttribute('href'))!, page.url());
                expect(url.pathname).toBe('/doi-search');
                expect([...url.searchParams.entries()]).toEqual([['datacenter[]', centre.datacenterName]]);
                await expect(link).toContainText(centre.shortName);
                await expect(cell.getByRole('link', { name: /^About this data centre:/ })).toHaveAttribute(
                    'href',
                    `/data-centres/description#${centre.slug}`,
                );
                await link.scrollIntoViewIfNeeded();
                if (centre.logo) {
                    await expect
                        .poll(() => cell.locator('img').evaluate((image: HTMLImageElement) => image.complete && image.naturalWidth > 0))
                        .toBe(true);
                }
                const box = await link.boundingBox();
                expect(box!.x).toBeGreaterThanOrEqual(0);
                expect(box!.x + box!.width).toBeLessThanOrEqual(width + 1);
            }
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
            await page.screenshot({ path: testInfo.outputPath(`data-centres-${width}.png`), fullPage: true });
        });
    }

    test('supports accordion keyboard interaction, direct links and browser history', async ({ page }) => {
        await useDataCentreCatalogue(page);
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await openDataCentres(page, '/data-centres/description');
        const triggers = page.getByRole('main').getByRole('button');
        await expect(triggers).toHaveCount(dataCentres.length);
        for (const trigger of await triggers.all()) await expect(trigger).toHaveAttribute('aria-expanded', 'false');
        const fid = page.locator('#fid-geo').getByRole('button');
        const gfz = page.locator('#gfz').getByRole('button');
        await fid.focus();
        await page.keyboard.press('Enter');
        await gfz.focus();
        await page.keyboard.press('Space');
        await expect(fid).toHaveAttribute('aria-expanded', 'true');
        await expect(gfz).toHaveAttribute('aria-expanded', 'true');
        await page
            .locator('#fid-geo')
            .getByRole('link', { name: /^Direct link:/ })
            .click();
        await expect(fid).toBeFocused();
        await page
            .locator('#gfz')
            .getByRole('link', { name: /^Direct link:/ })
            .click();
        await expect(gfz).toBeFocused();
        await fid.click();
        await expect(fid).toHaveAttribute('aria-expanded', 'false');
        await page.goBack();
        await expect(fid).toHaveAttribute('aria-expanded', 'true');
        await expect(fid).toBeFocused();
        await page.goForward();
        await expect(gfz).toBeFocused();
        await gfz.click();
        await expect(gfz).toHaveAttribute('aria-expanded', 'false');
        await openDataCentres(page, '/data-centres/description#fid%2Dgeo');
        await expect(fid).toHaveAttribute('aria-expanded', 'true');
        await expect(fid).toBeFocused();
        await expect(page.locator('#gfz').getByRole('button')).toHaveAttribute('aria-expanded', 'false');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(fid).toHaveAttribute('aria-expanded', 'true');
        await expect(fid).toBeFocused();
    });

    test('opens a data centre search with one tap on a touch screen', async ({ browser, baseURL }) => {
        const context = await browser.newContext({
            baseURL,
            viewport: { width: 390, height: 844 },
            hasTouch: true,
            ignoreHTTPSErrors: true,
            extraHTTPHeaders: { 'X-ERNIE-Playwright-Test': '1' },
        });
        try {
            const page = await context.newPage();
            await useDataCentreCatalogue(page);
            await openDataCentres(page);
            const link = page.locator('.data-centre-link').first();
            await expect(link.locator('.data-centre-label')).toBeVisible();
            await expect(link.locator('.data-centre-overlay')).toHaveCSS('opacity', '0');
            await link.tap();
            await expect(page).toHaveURL(/\/doi-search\?/);
            expect([...new URL(page.url()).searchParams.entries()]).toEqual([
                [expect.stringMatching(/^datacenter\[(?:0)?\]$/), dataCentres[0].datacenterName],
            ]);
        } finally {
            await context.close();
        }
    });

    for (const width of [390, 1440]) {
        test(`navigates through the live catalogue into the filtered DOI portal at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await openFind(page);
            const mobile = width < 768;
            await page.getByRole('button', { name: mobile ? 'Open menu' : 'Find', exact: true }).click();
            const menu = mobile ? page.getByTestId('mobile-menu') : page.getByRole('menu');
            await menu.getByRole(mobile ? 'link' : 'menuitem', { name: 'Data Centres', exact: true }).click();
            await expect(page).toHaveURL(/\/data-centres$/);
            await expect(page.getByRole('heading', { level: 1 })).toHaveText('Data Centres');
            await page.getByRole('button', { name: mobile ? 'Open menu' : 'Find', exact: true }).click();
            await expect(page.getByRole(mobile ? 'link' : 'menuitem', { name: 'Data Centres', exact: true })).toHaveAttribute('aria-current', 'page');
            await page.keyboard.press('Escape');
            const links = page.getByRole('main').getByRole('link', { name: /^View publications:/ });
            if ((await links.count()) === 0) {
                await expect(page.getByText('No data centres with published DOI resources are currently available.')).toBeVisible();
                return;
            }
            const selected = new URL((await links.first().getAttribute('href'))!, page.url()).searchParams.get('datacenter[]')!;
            await page
                .getByRole('main')
                .getByRole('link', { name: /^About this data centre:/ })
                .first()
                .click();
            await expect(page).toHaveURL(/\/data-centres\/description#/);
            const trigger = page.getByRole('main').getByRole('button', { expanded: true });
            await expect(trigger).toHaveCount(1);
            await expect(trigger).toBeFocused();
            await page.getByRole('link', { name: 'Back to data centres' }).click();
            await expect(page).toHaveURL(/\/data-centres$/);
            await page
                .getByRole('main')
                .getByRole('link', { name: /^View publications:/ })
                .first()
                .click();
            await expect(page).toHaveURL(/\/doi-search\?/);
            // Inertia normalises [] to [0] when serialising a Link visit.
            expect([...new URL(page.url()).searchParams.entries()]).toEqual([[expect.stringMatching(/^datacenter\[(?:0)?\]$/), selected]]);
            if (mobile) await page.getByRole('button', { name: /^Filters/ }).click();
            const checkbox = page.getByRole('checkbox', { name: selected, exact: false });
            await expect(checkbox).toBeChecked();
            const search = page.getByRole('combobox', { name: 'Search', exact: true });
            await search.fill('geoscience');
            await search.press('Enter');
            await expect.poll(() => new URL(page.url()).searchParams.get('q')).toBe('geoscience');
            expect(
                [...new URL(page.url()).searchParams.entries()].filter(([key]) => /^datacenter\[\d*\]$/.test(key)).map(([, value]) => value),
            ).toEqual([selected]);
            await expect(checkbox).toBeChecked();
            await page.getByRole('button', { name: 'Clear all filters', exact: true }).click();
            await expect(page).toHaveURL(/\/doi-search$/);
            const datacenterPanel = page.getByRole('button', { name: 'Datacenter', exact: true });
            await expect(datacenterPanel).toHaveAttribute('aria-expanded', 'false');
            await datacenterPanel.click();
            await expect(checkbox).not.toBeChecked();
        });
    }

    test('handles an empty catalogue on both pages', async ({ page }) => {
        await useDataCentreCatalogue(page, []);
        for (const path of ['/data-centres', '/data-centres/description']) {
            await openDataCentres(page, path);
            await expect(page.getByText('No data centres with published DOI resources are currently available.')).toBeVisible();
            await expect(page.getByRole('link', { name: 'Explore the Data Portal' })).toHaveAttribute('href', '/doi-search');
        }
    });
});
