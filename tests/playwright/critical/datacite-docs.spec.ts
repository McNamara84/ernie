import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

for (const format of ['json', 'jsonld']) {
    test(`the ${format} guide explains the exclusive polygon alternatives`, async ({ page }, testInfo) => {
        const id = 'datacite-geoLocations-geoLocationPolygon';
        const url = `/schemas/datacite/4.7/docs/${format}#${id}`;

        for (const [index, name] of ['polygonPoint', 'inPolygonPoint'].entries()) {
            await page.goto(url);
            const choices = page.getByRole('region', { name: 'Alternatives', exact: true });
            await expect(choices).toContainText('each array item must match exactly one', { ignoreCase: true });
            await expect(choices.getByRole('listitem')).toHaveCount(2);
            if (format === 'jsonld') await expect(choices).toContainText('After conversion to DataCite JSON');
            if (testInfo.project.name === 'chromium' && index === 0) {
                expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
            }

            await choices.getByRole('link', { name, exact: true }).click();
            await expect(page.locator('.igsn-doc-facts .igsn-obligation')).toHaveText('Required in some cases');
            await expect(page.locator('.igsn-doc-facts')).toContainText('0..1');
            await expect(page.locator('.igsn-doc-conditional')).toContainText(`Required in alternative ${index + 1}`);
        }
    });
}

test('the public guide connects the JSON and JSON-LD references for the same field', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1440, height: 1050 });
    await page.goto('/schemas/datacite/docs');
    await expect(page).toHaveURL(/\/schemas\/datacite\/4\.7\/docs$/);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('DataCite JSON and JSON-LD');
    if (testInfo.project.name === 'chromium') {
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath('datacite-overview.png'), fullPage: true });
    }
    await page.getByRole('link', { name: 'Explore JSON', exact: true }).click();
    await page.getByRole('searchbox', { name: 'Search the fields' }).fill('resourceTypeGeneral');
    await page.getByRole('searchbox', { name: 'Search the fields' }).press('Enter');
    await expect(page.getByRole('heading', { name: 'General resource type', level: 2 })).toBeVisible();
    await expect(page.getByText('Poster', { exact: true })).toBeVisible();
    const switcher = page.getByRole('link', { name: 'JSON-LD', exact: true });
    await expect(switcher).toHaveAttribute('href', /\/docs\/jsonld#datacite-types-resourceTypeGeneral$/);
    await switcher.click();
    await expect(page.getByText('$.resourceType.attrs.resourceTypeGeneral', { exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Linked Data meaning' })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Cite the DataCite metadata standard' })).toContainText('DataCite Metadata Working Group. (2026).');
    await page.reload();
    await expect(page.getByRole('heading', { name: 'General resource type', level: 2 })).toBeVisible();
    await page.getByRole('button', { name: 'Start here', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'General resource type', level: 2 })).toHaveCount(0);
    await page.getByRole('searchbox', { name: 'Search the fields' }).fill('resourceTypeGeneral');
    await page.getByRole('searchbox', { name: 'Search the fields' }).press('Enter');
    await expect(page.getByRole('heading', { name: 'General resource type', level: 2 })).toBeVisible();
    await expect(page.getByRole('main')).toBeFocused();
    if (testInfo.project.name === 'chromium') {
        expect((await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa']).analyze()).violations).toEqual([]);
        await page.screenshot({ path: testInfo.outputPath('datacite-jsonld-reference.png'), fullPage: true });
    }
});

test('the JSON-LD guide offers the exact published-profile example as a download', async ({ page }) => {
    await page.goto('/schemas/datacite/4.7/v1/docs/jsonld#examples');
    await page.getByRole('button', { name: 'Extended record', exact: true }).click();
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download JSON-LD', exact: true }).click();
    const file = await download;
    expect(file.suggestedFilename()).toBe('datacite-4.7-full.jsonld');
    const stream = await file.createReadStream();
    if (!stream) throw new Error('The JSON-LD example has no readable content.');
    const chunks: Buffer[] = [];
    for await (const chunk of stream) chunks.push(Buffer.from(chunk));
    const record = JSON.parse(Buffer.concat(chunks).toString('utf8'));
    expect(record['@context']).toMatch(/\/schemas\/datacite\/4\.7\/v1\/context\.jsonld$/);
    expect(record['@type']).toBe('Resource');
    expect(record.creators.creator).toHaveLength(2);
});

test('the reference works on a phone and keeps long profile paths inside the viewport', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/schemas/datacite/4.7/docs/jsonld#datacite-creators-name');
    await expect(page.getByRole('heading', { name: 'Name', level: 2 })).toBeVisible();
    await page.locator('#igsn-doc-main').evaluate((element) => element.scrollIntoView({ block: 'start', behavior: 'instant' }));
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    if (testInfo.project.name === 'chromium') await page.screenshot({ path: testInfo.outputPath('datacite-phone.png'), fullPage: true });
});
