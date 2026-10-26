import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

test('downloads ISO JSON next to XML on a resource landing page', async ({ page, browserName }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('mandatory-fields-only');
    await landingPage.verifyPageLoaded();
    const jsonLink = page.getByRole('link', { name: 'Download ISO/TR 19115-4 metadata as JSON (Preview)' });
    await expect(jsonLink).toBeVisible();
    await expect(jsonLink).toHaveText('JSON');
    await expect(jsonLink).toHaveAttribute('title', 'Download as ISO/TR 19115-4 JSON (Preview)');
    await expect(page.getByLabel('ISO 19115 metadata available', { exact: true })).toHaveText('ISO 19115');
    await expect(page.getByRole('link', { name: 'Download ISO 19115-3:2023 metadata as XML' })).toHaveText('XML');
    const exportUrl = await jsonLink.getAttribute('href');
    expect(exportUrl).toMatch(/\/metadata\/iso-19115-4\.json$/);
    const filename = 'mandatory-fields-only-iso-19115-4.json';
    let content: string;

    if (browserName === 'webkit') {
        // Playwright's Linux WebKit renders attachment navigations instead of
        // emitting a download: https://github.com/microsoft/playwright/issues/34076.
        // Check the real click response, including the attachment contract.
        const [response] = await Promise.all([page.waitForResponse((result) => result.url() === exportUrl), jsonLink.click()]);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toBe('application/json; charset=UTF-8');
        expect(response.headers()['content-disposition']).toBe(`attachment; filename="${filename}"`);
        content = await response.text();
    } else {
        const [download] = await Promise.all([page.waitForEvent('download'), jsonLink.click()]);
        expect(download.url()).toBe(exportUrl);
        expect(download.suggestedFilename()).toBe(filename);
        expect(await download.failure()).toBeNull();
        const stream = await download.createReadStream();
        if (!stream) throw new Error('Metadata download has no readable content');
        const chunks: Buffer[] = [];
        for await (const chunk of stream) chunks.push(Buffer.from(chunk));
        content = Buffer.concat(chunks).toString('utf8');
    }

    const metadata = JSON.parse(content);
    expect(metadata.type).toBe('Feature');
    expect(metadata.properties.identificationInfo.citation.title).toBe('TEST: Mandatory Fields Only');
    expect(metadata.conformsTo).toEqual(['https://schemas.isotc211.org/json/19115/-4/mdj/1.0.0/19115-4.json']);
});

test('keeps the ISO JSON action visible on a narrow dark landing page', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 850 });
    await page.emulateMedia({ colorScheme: 'dark' });
    const landingPage = new LandingPage(page);
    await landingPage.goto('mandatory-fields-only');
    const link = page.getByRole('link', { name: 'Download ISO/TR 19115-4 metadata as JSON (Preview)' });
    await link.scrollIntoViewIfNeeded();
    await expect(link).toBeVisible();
    const bounds = await link.boundingBox();
    expect(bounds).not.toBeNull();
    expect(bounds!.x).toBeGreaterThanOrEqual(0);
    expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(375);
});
