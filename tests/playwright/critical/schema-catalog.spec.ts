import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

import { navigateWithTlsRetry, prepareHtmlInterception } from '../helpers/navigation';

for (const guide of ['docs', 'docs/json']) {
    test(`the DataCite ${guide} catalogue link preserves the deployment prefix`, async ({ page }) => {
        await prepareHtmlInterception(page);
        // Emulate a proxy mounting this root-based test server below /ernie.
        // Production supplies the same prefix through its shared Inertia props.
        await page.route('**/ernie/schemas/**', async (route) => {
            const target = new URL(route.request().url());
            const originalPath = target.pathname + target.search;
            target.pathname = target.pathname.slice('/ernie'.length);
            const response = await route.fetch({ url: target.toString() });
            const html = (await response.text()).replace(
                /(<script[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/,
                (_match, open: string, raw: string, close: string) => {
                    const payload = JSON.parse(raw);
                    payload.props.pathPrefix = '/ernie';
                    payload.url = originalPath;
                    return open + JSON.stringify(payload).replaceAll('<', '\\u003c') + close;
                },
            );
            await route.fulfill({ response, body: html });
        });

        await navigateWithTlsRetry(page, `/ernie/schemas/datacite/4.7/${guide}`, { waitUntil: 'domcontentloaded' });
        const catalogue = page.getByRole('link', { name: 'All schemas', exact: true });
        await expect(catalogue).toHaveAttribute('href', '/ernie/schemas/docs');
        await catalogue.click();
        await expect(page).toHaveURL(/\/ernie\/schemas\/docs$/);
        await expect(page.getByRole('article')).toHaveCount(3);
    });
}

test('visitors can find every metadata guide and fetch the published files from the catalogue', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto('/schemas/docs');
    await expect(page).toHaveTitle(/Metadata schemas/);
    const navigation = page.getByRole('navigation', { name: 'Portal navigation' });
    await expect(navigation).toBeVisible();
    await expect(navigation.getByRole('button', { name: 'Find', exact: true })).not.toHaveAttribute('aria-current');
    await navigation.getByRole('button', { name: 'Find', exact: true }).click();
    await expect(page.getByRole('menuitem', { name: 'Data Portal', exact: true })).toHaveAttribute('href', '/doi-search');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('article')).toHaveCount(3);
    if (testInfo.project.name === 'chromium') {
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath('schema-catalog-desktop.png'), fullPage: true });
    }
    for (const name of ['IGSN Core XML', 'DataCite JSON', 'DataCite JSON-LD']) {
        const card = page.getByRole('article', { name, exact: true });
        for (const link of [
            card.locator('dl a').first(),
            card.getByRole('link', { name: 'Fixed revision file' }),
            card.getByRole('link', { name: 'Files and checksums' }),
        ]) {
            const href = await link.getAttribute('href');
            expect(href).toBeTruthy();
            const response = await page.request.get(href!);
            expect(response.ok(), href!).toBe(true);
        }
        await card.getByRole('link', { name: `Read documentation: ${name}` }).click();
        await expect(page.getByRole('heading', { level: 1 })).toContainText(name);
        await expect(navigation).toBeVisible();
        await page.getByRole('link', { name: 'All schemas', exact: true }).click();
        await expect(page).toHaveURL(/\/schemas\/docs$/);
    }
    await expect(page.getByRole('heading', { name: 'Supporting schema', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Choose a fixed revision for repeatable results', exact: true })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'A context gives meaning to Linked Data', exact: true })).toHaveCount(0);
});

test('the catalogue and its return link work on a phone without horizontal overflow', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/schemas/docs');
    await expect(page.getByRole('article')).toHaveCount(3);
    await page.getByRole('button', { name: 'Open menu', exact: true }).click();
    const navigation = page.getByRole('navigation', { name: 'Portal navigation' });
    await expect(navigation.getByRole('link', { name: 'Data Portal', exact: true })).toHaveAttribute('href', '/doi-search');
    await page.getByRole('button', { name: 'Close menu', exact: true }).click();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (testInfo.project.name === 'chromium') await page.screenshot({ path: testInfo.outputPath('schema-catalog-phone.png'), fullPage: true });
    await page.getByRole('link', { name: 'Read documentation: DataCite JSON-LD' }).click();
    await expect(page.getByRole('link', { name: 'All schemas', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('link', { name: 'All schemas', exact: true }).click();
    await expect(page).toHaveURL(/\/schemas\/docs$/);
});
