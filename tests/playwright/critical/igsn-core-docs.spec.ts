import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('visitors can search the published schema, follow an element link and download a valid example', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto('/schemas/igsn/2.0/docs');
    await expect(page.getByRole('heading', { level: 1 })).toContainText('IGSN Core XML');
    if (testInfo.project.name === 'chromium') {
        await page.screenshot({ path: testInfo.outputPath('guide-desktop.png'), fullPage: true });
        const accessibility = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze();
        expect(accessibility.violations).toEqual([]);
    }
    await page.getByRole('searchbox', { name: 'Search the schema' }).fill('sampletScheme');
    await page.getByRole('searchbox', { name: 'Search the schema' }).press('Enter');
    await expect(page.getByRole('heading', { name: 'Sample type', level: 2 })).toBeVisible();
    await expect(page.getByText('igsn:sampletScheme', { exact: true })).toBeVisible();
    if (testInfo.project.name === 'chromium') {
        await page.screenshot({ path: testInfo.outputPath('element-desktop.png'), fullPage: true });
    }
    await expect(page).toHaveURL(/#igsn-resource-igsn-entityTypeClassifications-igsn-sampleType$/);
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Sample type', level: 2 })).toBeVisible();
    await page.getByRole('button', { name: 'XML examples', exact: true }).click();
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download XML', exact: true }).click();
    expect((await download).suggestedFilename()).toBe('igsn-core-2.0-minimal.xml');
});

test('the guide remains usable on a phone without horizontal overflow', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/schemas/igsn/2.0/docs');
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    if (testInfo.project.name === 'chromium') {
        await page.screenshot({ path: testInfo.outputPath('guide-phone.png'), fullPage: true });
    }
    await page.getByRole('searchbox', { name: 'Search the schema' }).fill('identifierType');
    await page.getByRole('searchbox', { name: 'Search the schema' }).press('Enter');
    await expect(page.getByRole('heading', { name: 'IGSN identifier', level: 2 })).toBeVisible();
    await expect(page.getByRole('main')).toBeFocused();
    await expect(page.getByRole('button', { name: 'Open schema navigation' })).toHaveAttribute('aria-expanded', 'false');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});

test('the example panel grows on wide screens and the full citation is available', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto('/schemas/igsn/2.0/docs');
    const panel = page.locator('.igsn-doc-example-panel');
    const standardWidth = (await panel.boundingBox())!.width;
    await page.setViewportSize({ width: 1920, height: 1080 });
    await expect.poll(async () => (await panel.boundingBox())!.width).toBeGreaterThan(standardWidth + 150);
    expect((await panel.boundingBox())!.width).toBeGreaterThan(650);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (testInfo.project.name === 'chromium') await page.screenshot({ path: testInfo.outputPath('igsn-wide-layout.png') });
    await expect(page.getByRole('option', { name: 'Earlier schema snapshot' })).toHaveCount(0);
    const retired = await page.request.get('/schemas/igsn/2.0/preview-1/core.xsd');
    expect(retired.status()).toBe(404);
    await page.getByRole('link', { name: 'How to cite this schema', exact: true }).click();
    const citation = page.getByRole('region', { name: 'Recommended citation' });
    await expect(citation).toContainText(
        'Antipanova, T., Wieczorek, M., Heim, B., Krahl, R., Rial, K., Brauser, A., Sedeqi, M. R., Elger, K. (2026).',
    );
    await expect(citation.getByRole('link')).toHaveAttribute('href', 'https://doi.org/10.5281/zenodo.23831602');
    if (testInfo.project.name === 'chromium') await citation.screenshot({ path: testInfo.outputPath('igsn-citation.png') });
});
