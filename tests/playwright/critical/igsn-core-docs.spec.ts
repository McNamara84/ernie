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
    await page.getByRole('navigation', { name: 'In this schema' }).getByRole('link', { name: /Sample type/ }).click();
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
    await page.getByRole('button', { name: 'Open schema navigation' }).click();
    await page.getByRole('navigation', { name: 'In this schema' }).getByRole('link', { name: /IGSN identifier/ }).click();
    await expect(page.getByRole('heading', { name: 'IGSN identifier', level: 2 })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
