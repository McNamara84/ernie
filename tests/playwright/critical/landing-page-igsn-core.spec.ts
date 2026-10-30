import { expect, test } from '@playwright/test';

import { LandingPage } from '../helpers/page-objects/LandingPage';

test('downloads validated Core XML from a published IGSN and exposes the portable schema', async ({ page }) => {
    await new LandingPage(page).goto('playwright-legacy-igsn');
    const button = page.getByRole('button', { name: 'Download IGSN 2.0 Core metadata as XML (Preview)' });
    await expect(button).toBeVisible();
    const [download] = await Promise.all([page.waitForEvent('download'), button.click()]);
    expect(download.suggestedFilename()).toBe('igsn-core-2.0.xml');
    expect(await download.failure()).toBeNull();
    const stream = await download.createReadStream();
    if (!stream) throw new Error('Core XML download has no readable content');
    const chunks: Buffer[] = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    const xml = Buffer.concat(chunks).toString('utf8');
    expect(xml).toContain('urn:FAIR-AIMS:Core:1');
    expect(xml).toContain('igsn:identifierType="DOI"');
    expect(xml).not.toContain('playwright-current-repository@example.org');
    const schemaUrl = new URL('/schemas/igsn/2.0/core.xsd', page.url()).href;
    expect(xml).toContain(`xsi:schemaLocation="urn:FAIR-AIMS:Core:1 ${schemaUrl}"`);
    const schemaLink = page.getByRole('link', { name: 'IGSN Core schema' });
    await expect(schemaLink).toHaveAttribute('href', schemaUrl);
    const schema = await page.request.get(schemaUrl);
    expect(schema.ok()).toBeTruthy();
    const schemaXml = await schema.text();
    expect(schemaXml).toContain('schemaLocation="DataCite4.7/metadata.xsd"');
    expect(schemaXml).not.toMatch(/schemaLocation="[A-Za-z]:[\\/]/);

    const dataCite = await page.request.get(new URL('DataCite4.7/metadata.xsd', schema.url()).href);
    expect(dataCite.ok()).toBeTruthy();
    expect(await dataCite.text()).toContain('schemaLocation="include/xml.xsd"');
    const xmlSchema = await page.request.get(new URL('include/xml.xsd', dataCite.url()).href);
    expect(xmlSchema.ok()).toBeTruthy();
    expect(await xmlSchema.text()).toContain('targetNamespace="http://www.w3.org/XML/1998/namespace"');
});

test('keeps Core off ordinary resource pages', async ({ page }) => {
    const landingPage = new LandingPage(page);
    await landingPage.goto('mandatory-fields-only');
    await landingPage.verifyPageLoaded();
    await expect(page.getByText('IGSN 2.0 Core (Preview)', { exact: true })).toHaveCount(0);
});

test('shows Core validation errors without navigation or a download', async ({ page }) => {
    await new LandingPage(page).goto('playwright-legacy-igsn');
    await page.route('**/metadata/igsn-core-2.0.xml', (route) =>
        route.fulfill({ status: 422, contentType: 'application/json', body: '{"code":"igsn_core_metadata_incomplete"}' }),
    );
    await page.getByRole('button', { name: 'Download IGSN 2.0 Core metadata as XML (Preview)' }).click();
    await expect(page.getByRole('alert')).toContainText('Required metadata is missing or unsupported.');
    await expect(page).toHaveURL(/playwright-legacy-igsn$/);
});
