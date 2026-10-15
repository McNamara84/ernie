import { expect } from '@playwright/test';

import type { GeoLocation } from '../../../resources/js/lib/landing-page-geolocation';
import { test } from '../fixtures/client-identity';
import { navigateWithTlsRetry } from '../helpers/navigation';

const point: GeoLocation = {
    id: 1,
    place: null,
    geo_type: 'point',
    point_latitude: -89.999997,
    point_longitude: -171.470888,
    west_bound_longitude: null,
    east_bound_longitude: null,
    south_bound_latitude: null,
    north_bound_latitude: null,
    polygon_points: null,
};

for (const preview of [false, true]) {
    for (const [name, locations] of [
        ['identical polar points', [point, { ...point, id: 2 }, { ...point, id: 3 }]],
        ['different projected polar points', [point, { ...point, id: 2, point_latitude: -89.999996 }]],
        ['identical midlatitude points', [1, 2, 3].map((id) => ({ ...point, id, point_latitude: 52.38, point_longitude: 13.06 }))],
        [
            'mixed valid and malformed geometries',
            [
                { ...point, point_latitude: 52.38 },
                { ...point, id: 2, polygon_points: [{ latitude: 500, longitude: 0 }] },
            ],
        ],
    ] as const) {
        test(`${preview ? 'preview' : 'public'} landing page handles ${name}`, async ({ page }) => {
            const errors: string[] = [];
            let injected = false;
            page.on('pageerror', (error) => errors.push(error.message));
            // Exercise the complete built page with legacy data, without changing saved fixtures.
            await page.route('**/10.5880/**', async (route) => {
                if (route.request().resourceType() !== 'document') return route.continue();
                const response = await route.fetch();
                let html = await response.text();
                const script = /(<script[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
                html = html.replace(script, (_match, open: string, raw: string, close: string) => {
                    const payload = JSON.parse(raw);
                    if (payload.component === 'LandingPages/default_gfz') {
                        payload.props.resource.geo_locations = locations;
                        injected = true;
                    }
                    return open + JSON.stringify(payload).replaceAll('<', '\\u003c') + close;
                });
                await route.fulfill({ status: response.status(), contentType: 'text/html', body: html });
            });
            const lookup = await page.request.get('/_test/landing-page-by-slug/fully-populated');
            expect(lookup.ok()).toBe(true);
            const urls = (await lookup.json()) as { public_url: string; preview_url: string };
            const target = new URL(preview ? urls.preview_url : urls.public_url, 'https://example.test');
            await navigateWithTlsRetry(page, target.pathname + target.search, { waitUntil: 'domcontentloaded' });
            expect(injected).toBe(true);
            await expect(page.locator('h1')).toBeVisible();
            await expect(page.locator('.leaflet-container')).toBeVisible();
            await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible();
            if (name === 'mixed valid and malformed geometries') {
                await expect(page.getByTestId('invalid-geolocation-message')).toBeVisible();
            }
            await page.locator('.leaflet-control-zoom-in').click();
            expect(errors).toEqual([]);
            await expect(page.getByRole('status').filter({ hasText: 'map is currently unavailable' })).toHaveCount(0);
        });
    }
}
