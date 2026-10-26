import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

test('downloads ISO JSON next to XML on a resource landing page', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('mandatory-fields-only');
    await landingPage.verifyPageLoaded();
    const jsonLink = page.getByRole('link', { name: 'Download ISO/TR 19115-4 metadata as JSON (Preview)' });
    await expect(jsonLink).toBeVisible();
    await expect(jsonLink).toHaveText('ISO/TR 19115-4 · JSON (Preview)');
    await expect(page.getByRole('link', { name: 'Download ISO 19115-3:2023 metadata as XML' })).toBeVisible();
    const downloadEvent = page.waitForEvent('download');
    await jsonLink.click();
    const download = await downloadEvent;
    expect(download.suggestedFilename()).toMatch(/-iso-19115-4\.json$/);
    const stream = await download.createReadStream();
    if (!stream) throw new Error('Metadata download has no readable content');
    const chunks: Buffer[] = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    const metadata = JSON.parse(Buffer.concat(chunks).toString('utf8'));
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
