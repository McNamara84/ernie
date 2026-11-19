import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('visitors can find every metadata guide and fetch the published files from the catalogue', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto('/schemas/docs');
    await expect(page.getByRole('heading', { name: 'Metadata schemas', level: 1 })).toBeVisible();
    await expect(page.getByRole('article')).toHaveCount(3);
    if (testInfo.project.name === 'chromium') {
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath('schema-catalog-desktop.png'), fullPage: true });
    }
    for (const name of ['IGSN Core XML', 'DataCite JSON', 'DataCite JSON-LD']) {
        const card = page.getByRole('article', { name, exact: true });
        for (const link of [card.locator('dl a').first(), card.getByRole('link', { name: 'Fixed revision file' }), card.getByRole('link', { name: 'Files and checksums' })]) {
            const href = await link.getAttribute('href');
            expect(href).toBeTruthy();
            const response = await page.request.get(href!);
            expect(response.ok(), href!).toBe(true);
        }
        await card.getByRole('link', { name: `Read documentation: ${name}` }).click();
        await expect(page.getByRole('heading', { level: 1 })).toContainText(name);
        await page.getByRole('link', { name: 'All schemas', exact: true }).click();
        await expect(page).toHaveURL(/\/schemas\/docs$/);
    }
    const envelope = await page.request.get('/schemas/oai-pmh/dcat-ap/3.0.1/rdf-envelope.xsd');
    expect(envelope.ok()).toBe(true);
    expect(await envelope.text()).toContain('OAI envelope validation only');
    await page.getByRole('link', { name: 'OAI-PMH documentation', exact: true }).click();
    await expect(page).toHaveURL(/\/oai-pmh\/docs$/);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
});

test('the catalogue and its return link work on a phone without horizontal overflow', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/schemas/docs');
    await expect(page.getByRole('article')).toHaveCount(3);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (testInfo.project.name === 'chromium') await page.screenshot({ path: testInfo.outputPath('schema-catalog-phone.png'), fullPage: true });
    await page.getByRole('link', { name: 'Read documentation: DataCite JSON-LD' }).click();
    await expect(page.getByRole('link', { name: 'All schemas', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    await page.getByRole('link', { name: 'All schemas', exact: true }).click();
    await expect(page).toHaveURL(/\/schemas\/docs$/);
});
