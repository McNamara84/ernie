import { expect } from '@playwright/test';

import type { LandingPageSubject } from '../../../resources/js/types/landing-page';
import { test } from '../fixtures/client-identity';
import { navigateWithTlsRetry, prepareHtmlInterception } from '../helpers/navigation';

// The PHP import/roundtrip tests verify these server projections against real stored rows.
// This browser test checks their rendered badges and portal filter targets.
test('renders repaired keyword identities and retains distinct same-label subjects', async ({ page }) => {
    const aquifer = 'https://gcmd.earthdata.nasa.gov/kms/concept/a957363b-2f2c-4169-a656-c2f24933eb72';
    const instrument = 'https://gcmd.earthdata.nasa.gov/kms/concept/ff564c87-78f2-47eb-a857-a4bdc0a71ae5';
    const subjects: LandingPageSubject[] = [
        {
            id: 1,
            subject: 'AQUIFERS',
            subject_scheme: 'Science Keywords',
            scheme_uri: null,
            value_uri: aquifer,
            classification_code: null,
            breadcrumb_path: 'EARTH SCIENCE > TERRESTRIAL HYDROSPHERE > GROUND WATER > GROUND WATER FEATURES > AQUIFERS',
        },
        {
            id: 2,
            subject: 'In Situ/Laboratory Instruments',
            subject_scheme: 'Instruments',
            scheme_uri: null,
            value_uri: instrument,
            classification_code: null,
            breadcrumb_path: 'In Situ/Laboratory Instruments',
        },
        { id: 3, subject: 'heat storage', subject_scheme: null, scheme_uri: null, value_uri: null, classification_code: null, breadcrumb_path: null },
        {
            id: 4,
            subject: 'heat storage',
            subject_scheme: 'GEMET',
            scheme_uri: null,
            value_uri: 'http://www.eionet.europa.eu/gemet/concept/4026',
            classification_code: null,
            breadcrumb_path: null,
        },
    ];
    await prepareHtmlInterception(page);
    let injected = false;
    await page.route('**/10.5880/**', async (route) => {
        if (route.request().resourceType() !== 'document') return route.continue();
        const response = await route.fetch();
        const html = (await response.text()).replace(
            /(<script[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/,
            (_match, open: string, raw: string, close: string) => {
                const payload = JSON.parse(raw);
                if (payload.component === 'LandingPages/default_gfz') {
                    payload.props.resource.subjects = subjects;
                    injected = true;
                }
                return open + JSON.stringify(payload).replaceAll('<', '\\u003c') + close;
            },
        );
        await route.fulfill({ status: response.status(), contentType: 'text/html', body: html });
    });
    const lookup = await page.request.get('/_test/landing-page-by-slug/fully-populated');
    expect(lookup.ok()).toBe(true);
    const urls = (await lookup.json()) as { public_url: string };
    const target = new URL(urls.public_url, 'https://example.test');
    await navigateWithTlsRetry(page, target.pathname, { waitUntil: 'domcontentloaded' });
    expect(injected).toBe(true);
    const section = page.getByTestId('subjects-section');
    await expect(section.locator('[data-slot="keyword-badge"]')).toHaveCount(4);
    const aquiferLink = section.getByRole('link', { name: 'EARTH SCIENCE > ... > GROUND WATER FEATURES > AQUIFERS', exact: true });
    await expect(aquiferLink).toHaveCount(1);
    await expect(section.getByRole('link', { name: 'In Situ/Laboratory Instruments', exact: true })).toHaveCount(1);
    await expect(section.getByRole('link', { name: 'heat storage', exact: true })).toHaveCount(2);
    await expect(aquiferLink).toHaveAttribute('href', new RegExp(encodeURIComponent(aquifer)));
    await aquiferLink.click();
    await expect(page).toHaveURL(new RegExp('/doi-search\\?.*' + encodeURIComponent(aquifer)));
});
