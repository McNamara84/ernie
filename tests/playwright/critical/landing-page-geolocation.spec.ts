import { expect, type Locator, type Page } from '@playwright/test';

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

async function openLocationPage(page: Page, locations: readonly GeoLocation[], preview: boolean, igsn = false) {
    let injected = false;
    await page.route('**/10.5880/**', async (route) => {
        if (route.request().resourceType() !== 'document') return route.continue();
        const response = await route.fetch();
        let html = await response.text();
        const script = /(<script[^>]*data-page="app"[^>]*>)([\s\S]*?)(<\/script>)/;
        html = html.replace(script, (_match, open: string, raw: string, close: string) => {
            const payload = JSON.parse(raw);
            if (payload.component === 'LandingPages/default_gfz') {
                payload.props.resource.geo_locations = locations;
                if (igsn) {
                    payload.component = 'LandingPages/default_gfz_igsn';
                    payload.props.sectionOrder = null;
                }
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
    await page.locator('.leaflet-container').scrollIntoViewIfNeeded();
}

// Click actual painted geometry, avoiding line waypoints and concave path bounding-box centers.
async function clickPath(page: Page, path: Locator) {
    await path.scrollIntoViewIfNeeded();
    let position: { x: number; y: number } | null = null;
    // Closing a popup may still be panning the map. Poll for a visible painted segment before clicking.
    await expect
        .poll(async () => {
            position = await path.evaluate((element) => {
                const svgPath = element as SVGPathElement;
                const length = svgPath.getTotalLength();
                for (let distance = length * 0.1; distance < length * 0.9; distance += 2) {
                    const point = svgPath.getPointAtLength(distance);
                    const screenPoint = new DOMPoint(point.x, point.y).matrixTransform(svgPath.getScreenCTM()!);
                    if (document.elementFromPoint(screenPoint.x, screenPoint.y) === svgPath) {
                        return { x: screenPoint.x, y: screenPoint.y };
                    }
                }
                return null;
            });
            return position;
        })
        .not.toBeNull();
    // A horizontal SVG stroke has a zero-height bounding box, so Locator.click considers it invisible.
    // The hit-tested screen point still receives an ordinary, trusted mouse click.
    await page.mouse.click(position!.x, position!.y);
}

const describedLocations: GeoLocation[] = [
    { ...point, id: 11, point_latitude: 35, point_longitude: 80, place: 'Point coverage' },
    {
        ...point,
        id: 12,
        point_latitude: null,
        point_longitude: null,
        geo_type: 'box',
        place: 'Box coverage',
        west_bound_longitude: -80,
        east_bound_longitude: -40,
        south_bound_latitude: 20,
        north_bound_latitude: 50,
    },
    {
        ...point,
        id: 13,
        point_latitude: null,
        point_longitude: null,
        geo_type: 'polygon',
        place: 'Polygon coverage',
        polygon_points: [
            { latitude: -45, longitude: -80 },
            { latitude: -10, longitude: -60 },
            { latitude: -45, longitude: -40 },
        ],
    },
    {
        ...point,
        id: 14,
        point_latitude: null,
        point_longitude: null,
        geo_type: 'line',
        place: 'Line coverage',
        polygon_points: [
            { latitude: -30, longitude: 20 },
            { latitude: -30, longitude: 60 },
            { latitude: -30, longitude: 100 },
        ],
    },
];

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
    ] satisfies Array<[string, GeoLocation[]]>) {
        test(`${preview ? 'preview' : 'public'} landing page handles ${name}`, async ({ page }) => {
            const errors: string[] = [];
            page.on('pageerror', (error) => errors.push(error.message));
            // Exercise the complete built page with legacy data, without changing saved fixtures.
            await openLocationPage(page, locations, preview);
            await expect(page.locator('.leaflet-marker-icon').first()).toBeVisible();
            if (name === 'mixed valid and malformed geometries') {
                await expect(page.getByTestId('invalid-geolocation-message')).toBeVisible();
            }
            await page.locator('.leaflet-control-zoom-in').click();
            expect(errors).toEqual([]);
            await expect(page.getByRole('status').filter({ hasText: 'map is currently unavailable' })).toHaveCount(0);
        });
    }

    for (const igsn of [false, true]) {
        test(`${preview ? 'preview' : 'public'} ${igsn ? 'IGSN' : 'dataset'} map opens descriptions on every geometry and waypoint`, async ({
            page,
        }) => {
            await openLocationPage(page, describedLocations, preview, igsn);
            const map = page.locator('.leaflet-container');
            // Leave room for popup auto-panning, especially in the narrower IGSN map column.
            await map.getByRole('button', { name: 'Zoom out', exact: true }).click();
            const popup = map.locator('.leaflet-popup-content');
            const marker = map.locator('.leaflet-marker-icon');
            await marker.click();
            await expect(popup).toHaveText('Point coverage');
            await map.getByRole('button', { name: 'Close popup' }).click();
            await expect(popup).toHaveCount(0);
            await marker.focus();
            await marker.press('Enter');
            await expect(popup).toHaveText('Point coverage');
            await marker.press('Escape');
            await expect(popup).toHaveCount(0);

            for (const [label, text] of [
                ['bounding box', 'Box coverage'],
                ['polygon', 'Polygon coverage'],
                ['line', 'Line coverage'],
            ]) {
                const path = map.getByRole('button', { name: `Show coverage description for ${label}`, exact: true });
                if (label === 'line') await clickPath(page, path);
                else await path.click();
                await expect(popup).toHaveText(text);
                await expect(map.locator('.leaflet-popup')).toHaveCount(1);
                await path.focus();
                await path.press('Escape');
                await expect(popup).toHaveCount(0);
                for (const key of ['Enter', 'Space']) {
                    await path.focus();
                    await path.press(key);
                    await expect(popup).toHaveText(text);
                    await map.getByRole('button', { name: 'Close popup' }).click();
                    await expect(popup).toHaveCount(0);
                }
            }
            for (let index = 0; index < 3; index++) {
                const waypoint = map.getByRole('button', { name: `Show coverage description for waypoint ${index + 1}`, exact: true });
                await waypoint.hover();
                const coordinate = describedLocations[3].polygon_points![index];
                await expect(map.locator('.leaflet-tooltip')).toHaveText(
                    `Lat: ${coordinate.latitude.toFixed(6)}, Lon: ${coordinate.longitude.toFixed(6)}`,
                );
                await waypoint.click();
                await expect(popup).toHaveText('Line coverage');
                await expect(map.locator('.leaflet-tooltip')).toHaveCount(0);
                await map.getByRole('button', { name: 'Close popup' }).click();
                await expect(popup).toHaveCount(0);
                await waypoint.focus();
                await waypoint.press('Space');
                await expect(popup).toHaveText('Line coverage');
                await waypoint.press('Escape');
                await expect(popup).toHaveCount(0);
            }
            // Switching objects replaces the previous popup; background clicks close it.
            await marker.click();
            await map.getByRole('button', { name: 'Show coverage description for bounding box', exact: true }).click();
            await expect(map.locator('.leaflet-popup')).toHaveCount(1);
            await expect(popup).toHaveText('Box coverage');
            await map.click({ position: { x: 15, y: 80 } });
            await expect(popup).toHaveCount(0);
        });
    }

    test(`${preview ? 'preview' : 'public'} map keeps unlabelled geometry inert and waypoint coordinates hover-only`, async ({ page }) => {
        await openLocationPage(
            page,
            describedLocations.map((geo, index) => ({ ...geo, place: [null, '', ' \n ', null][index] })),
            preview,
        );
        const map = page.locator('.leaflet-container');
        const objects = map.locator('.leaflet-marker-icon, .leaflet-overlay-pane path');
        await expect(objects).toHaveCount(7);
        await expect(map.locator('[role="button"][aria-label^="Show coverage"]')).toHaveCount(0);
        for (const element of await objects.all()) {
            await expect(element).not.toHaveAttribute('tabindex');
            await expect(element).not.toHaveAttribute('role', 'button');
            expect(await element.evaluate((node) => getComputedStyle(node).cursor)).not.toBe('pointer');
            await element.dispatchEvent('click');
            await expect(map.locator('.leaflet-popup')).toHaveCount(0);
        }
        const waypoint = map.locator('.leaflet-overlay-pane path').last();
        // Touch-like activation has no preceding mouseover, so it must not open even a coordinate tooltip.
        await waypoint.dispatchEvent('click');
        await expect(map.locator('.leaflet-tooltip')).toHaveCount(0);
        await waypoint.hover();
        await expect(map.locator('.leaflet-tooltip')).toHaveText('Lat: -30.000000, Lon: 100.000000');
        await waypoint.click();
        await expect(map.locator('.leaflet-popup')).toHaveCount(0);
        await map.hover({ position: { x: 15, y: 80 } });
        await expect(map.locator('.leaflet-tooltip')).toHaveCount(0);
    });
}

for (const colorScheme of ['light', 'dark'] as const) {
    test(`map descriptions remain readable and scrollable on a narrow ${colorScheme} page`, async ({ page, browserName }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.emulateMedia({ colorScheme });
        const text =
            'München & Küste\n<img src=x onerror=alert(1)>\n' + 'https://example.test/'.repeat(30) + '\n' + 'Full coverage description\n'.repeat(50);
        await openLocationPage(page, [{ ...describedLocations[0], place: text }], false);
        const map = page.locator('.leaflet-container');
        await map.locator('.leaflet-marker-icon').click();
        const popup = map.locator('.leaflet-popup-content');
        await expect(popup).toHaveText(text);
        await expect(popup.locator('img')).toHaveCount(0);
        const metrics = await popup.evaluate((element) => {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = 1;
            const context = canvas.getContext('2d')!;
            const luminance = (color: string) => {
                context.fillStyle = color;
                context.fillRect(0, 0, 1, 1);
                const channels = Array.from(context.getImageData(0, 0, 1, 1).data)
                    .slice(0, 3)
                    .map((value) => {
                        const channel = value / 255;
                        return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
                    });
                return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
            };
            const foreground = luminance(getComputedStyle(element.firstElementChild!).color);
            const background = luminance(getComputedStyle(element.closest('.leaflet-popup-content-wrapper')!).backgroundColor);
            return {
                height: element.clientHeight,
                scrollHeight: element.scrollHeight,
                scrollWidth: element.scrollWidth,
                width: element.clientWidth,
                contrast: (Math.max(foreground, background) + 0.05) / (Math.min(foreground, background) + 0.05),
                whiteSpace: getComputedStyle(element.firstElementChild!).whiteSpace,
            };
        });
        expect(metrics.height).toBeLessThanOrEqual(240);
        expect(metrics.scrollHeight).toBeGreaterThan(metrics.height);
        expect(metrics.scrollWidth).toBeLessThanOrEqual(metrics.width + 1);
        expect(metrics.contrast).toBeGreaterThanOrEqual(4.5);
        expect(metrics.whiteSpace).toBe('pre-wrap');
        await popup.evaluate((element) => {
            element.scrollTop = element.scrollHeight;
        });
        await expect(map.getByRole('button', { name: 'Close popup' })).toBeVisible();
        const mapBounds = (await map.boundingBox())!;
        const popupBounds = (await map.locator('.leaflet-popup').boundingBox())!;
        expect(popupBounds.width).toBeLessThanOrEqual(mapBounds.width);
        expect(popupBounds.height).toBeLessThanOrEqual(mapBounds.height);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await page.setViewportSize({ width: 320, height: 844 });
        await map.scrollIntoViewIfNeeded();
        await expect
            .poll(async () => (await map.locator('.leaflet-popup').boundingBox())!.width)
            .toBeLessThanOrEqual((await map.boundingBox())!.width);
        await expect
            .poll(async () => (await map.locator('.leaflet-popup').boundingBox())!.height)
            .toBeLessThanOrEqual((await map.boundingBox())!.height);
        await map.getByRole('button', { name: 'Close popup' }).click();
        await expect(popup).toHaveCount(0);
        if (browserName === 'chromium') {
            await map.getByRole('button', { name: 'Fullscreen', exact: true }).click();
            await expect.poll(() => page.evaluate(() => document.fullscreenElement !== null)).toBe(true);
            await map.locator('.leaflet-marker-icon').click();
            await expect(popup).toHaveText(text);
            await map.getByRole('button', { name: 'Exit Fullscreen', exact: true }).click();
            await expect.poll(() => page.evaluate(() => document.fullscreenElement === null)).toBe(true);
            await expect(popup).toHaveText(text);
            await map.getByRole('button', { name: 'Close popup' }).click();
        }
    });
}
