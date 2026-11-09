import AxeBuilder from '@axe-core/playwright';
import { expect, type Page, test } from '@playwright/test';

import { navigateWithTlsRetry, prepareHtmlInterception } from '../helpers/navigation';

let appHtml: string | undefined;
const actionName = 'Download DCAT-AP 3.0.1 metadata as RDF/XML';
const exportUrl = '/10.5880/dcat-browser/metadata/dcat-ap.rdf';

// Exercise the real application template and CSS with controlled transport props.
// Actual RDF responses, eligibility and graph parity are tested by Pest and SHACL.
async function openPage(page: Page, preview = false, eligible = true, igsn = false) {
    await prepareHtmlInterception(page);
    await page.route('https://fonts.bunny.net/**', (route) => route.fulfill({ contentType: 'text/css', body: '' }));
    if (appHtml === undefined) {
        const response = await page.request.get('/login', { timeout: 60_000 });
        expect(response.ok()).toBe(true);
        appHtml = await response.text();
    }
    const script = /(<script\b[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
    expect(appHtml).toMatch(script);
    const body = appHtml;
    await page.route('**/dcat-browser-preview', (route) =>
        route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: body.replace(script, (_match, start: string, json: string, end: string) => {
                const payload = JSON.parse(json);
                payload.component = `LandingPages/${igsn ? 'default_gfz_igsn' : 'default_gfz'}`;
                payload.url = '/dcat-browser-preview';
                payload.props = {
                    ...payload.props,
                    isPreview: preview,
                    supportsDcatAp: eligible && !igsn,
                    metadataLinks:
                        eligible && !preview && !igsn
                            ? [
                                  {
                                      format: 'dcat-ap',
                                      standard: 'DCAT-AP',
                                      label: 'DCAT-AP 3.0.1 RDF/XML',
                                      url: exportUrl,
                                      mediaType: 'application/rdf+xml',
                                      profile: 'https://semiceu.github.io/DCAT-AP/releases/3.0.1/',
                                  },
                              ]
                            : [],
                    resource: {
                        id: 1374,
                        resource_type: { id: 1, name: igsn ? 'PhysicalObject' : 'Dataset' },
                        publication_year: 2026,
                        titles: [{ id: 1, title: 'DCAT browser fixture', title_type: 'MainTitle' }],
                        descriptions: [{ id: 1, value: 'A resource with the required description.', description_type: 'Abstract' }],
                        creators: [],
                        contributors: [],
                        funding_references: [],
                        subjects: [],
                        related_identifiers: [],
                        contact_persons: [],
                        geo_locations: [],
                        licenses: [],
                    },
                    landingPage: { id: 1374, status: preview ? 'draft' : 'published' },
                };
                return start + JSON.stringify(payload).replaceAll('<', '\\u003c') + end;
            }),
        }),
    );
    await navigateWithTlsRetry(page, '/dcat-browser-preview', { waitUntil: 'domcontentloaded' });
    await expect(page.getByRole('heading', { name: 'Download Metadata', exact: true })).toBeVisible();
}

for (const colorScheme of ['light', 'dark'] as const) {
    test(`DCAT download remains accessible on mobile and desktop in ${colorScheme} mode`, async ({ page }) => {
        await page.emulateMedia({ colorScheme });
        await openPage(page);
        const link = page.getByRole('link', { name: actionName });
        await expect(link).toHaveAttribute('href', exportUrl);
        await expect(page.getByText('DCAT-AP 3.0.1', { exact: true })).toBeVisible();
        for (const width of [375, 1440]) {
            await page.setViewportSize({ width, height: 900 });
            await link.scrollIntoViewIfNeeded();
            await expect(link).toBeVisible();
            const bounds = await link.boundingBox();
            expect(bounds!.x).toBeGreaterThanOrEqual(0);
            expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(width);
            const accessibility = await new AxeBuilder({ page }).include('section[aria-labelledby="heading-download-metadata"]').analyze();
            expect(accessibility.violations).toEqual([]);
        }
    });
}

test('DCAT preview opens and closes its dialog using the keyboard', async ({ page }) => {
    await openPage(page, true);
    const button = page.getByRole('button', { name: actionName });
    await button.focus();
    await page.keyboard.press('Enter');
    const dialog = page.getByRole('dialog', { name: 'Metadata download unavailable' });
    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeVisible();
    await expect(button).toBeFocused();
});

for (const igsn of [false, true]) {
    test(`does not offer DCAT for ${igsn ? 'IGSNs' : 'unavailable resources'}`, async ({ page }) => {
        await openPage(page, false, false, igsn);
        await expect(page.getByRole('link', { name: actionName })).toHaveCount(0);
        await expect(page.getByText('DCAT-AP 3.0.1', { exact: true })).toHaveCount(0);
    });
}
