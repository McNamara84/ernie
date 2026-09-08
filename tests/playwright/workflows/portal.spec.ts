import { expect, type Page, test } from '@playwright/test';

/**
 * Portal E2E Tests
 *
 * Tests for the public data portal page with search, filters, map and results.
 * The portal is publicly accessible (no login required).
 */

const searchInput = (page: Page) => page.getByRole('combobox', { name: 'Search' });

async function openPortal(page: Page, path = '/doi-search') {
    const navigate = () => page.goto(path, { waitUntil: 'domcontentloaded' as const, timeout: 60_000 });
    const response = await navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) {
            throw error;
        }

        // WebKit on Windows can reject the local Traefik certificate on the
        // first handshake even with ignoreHTTPSErrors enabled.
        return navigate();
    });

    expect(response, `Expected ${path} to return an HTTP response`).not.toBeNull();
    expect(response!.status(), `Expected ${path} to load without an HTTP error`).toBeLessThan(400);
    await expect(page.getByTestId('portal-workspace')).toBeVisible();
}

async function openFilterSection(page: Page, name: 'Resource Type' | 'Datacenter') {
    const trigger = page.getByRole('button', { name: new RegExp(`^${name}(?: \\d+)?$`) });
    await trigger.scrollIntoViewIfNeeded();

    if ((await trigger.getAttribute('data-state')) !== 'open') {
        await trigger.click();
    }

    await expect(trigger).toHaveAttribute('data-state', 'open');
}

test.describe('Portal Page', () => {
    test.beforeEach(async ({ page }) => {
        await openPortal(page);
    });

    test.describe('Page Loading', () => {
        test('portal page loads successfully', async ({ page }) => {
            await expect(page.getByTestId('portal-wordmark')).toBeVisible();
            await expect(page.getByRole('heading', { level: 1, name: 'GFZ Data Services Portal' })).toBeVisible();
        });

        test('displays filters sidebar', async ({ page }) => {
            const sidebar = page.getByTestId('portal-filter-sidebar');

            await expect(sidebar).toBeVisible();
            await expect(sidebar.getByText('Filters', { exact: true })).toBeVisible();
            await expect(sidebar.getByRole('button', { name: 'Resource Type', exact: true })).toBeVisible();
        });

        test('displays map component', async ({ page }) => {
            await expect(page.getByTestId('portal-map-container')).toBeVisible();
            await expect(page.locator('.leaflet-container').first()).toBeVisible();
        });

        test('displays results area', async ({ page }) => {
            const hasResults = await page.getByTestId('portal-results-list').first().isVisible();
            const hasEmptyState = await page.getByText(/no results/i).isVisible();
            expect(hasResults || hasEmptyState).toBe(true);
        });
    });

    test.describe('Find Navigation', () => {
        test('offers both portal destinations and navigates to the IGSN portal', async ({ page }) => {
            await page.getByRole('button', { name: 'Find', exact: true }).click();

            const dataPortalLink = page.getByRole('menuitem', { name: 'Data Portal' });
            const igsnPortalLink = page.getByRole('menuitem', { name: 'IGSN Portal' });
            await expect(dataPortalLink).toHaveAttribute('href', '/doi-search');
            await expect(igsnPortalLink).toHaveAttribute('href', '/igsn-search');

            await igsnPortalLink.click();

            await expect(page).toHaveURL(/\/igsn-search$/);
            await expect(page).toHaveTitle(/IGSN Portal/);
        });
    });

    test.describe('Search Functionality', () => {
        test('search input is focusable and accepts text', async ({ page }) => {
            const input = searchInput(page);
            await expect(input).toBeVisible();

            await input.fill('climate');
            await expect(input).toHaveValue('climate');
        });

        test('search updates URL with query parameter', async ({ page }) => {
            await searchInput(page).fill('test query');
            await page.getByRole('button', { name: 'Search', exact: true }).click();

            await expect(page).toHaveURL(/q=test(?:\+|%20)query/);
            expect(new URL(page.url()).searchParams.get('q')).toBe('test query');
        });

        test('clear search button clears the draft query', async ({ page }) => {
            await searchInput(page).fill('something');
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await expect(page).toHaveURL(/q=something/);

            await page.getByRole('button', { name: 'Clear search' }).click();

            await expect(searchInput(page)).toHaveValue('');
        });
    });

    test.describe('Type Filter', () => {
        test('displays resource type filter popover trigger', async ({ page }) => {
            await openFilterSection(page, 'Resource Type');
            await expect(page.getByRole('button', { name: 'All Resource Types' })).toBeVisible();
        });

        test('default selection shows All Resource Types', async ({ page }) => {
            await openFilterSection(page, 'Resource Type');
            const trigger = page.getByRole('button', { name: 'All Resource Types' });

            await expect(trigger).toBeVisible();
            await expect(trigger).toContainText('All Resource Types');
        });

        test('popover opens and shows search input', async ({ page }) => {
            await openFilterSection(page, 'Resource Type');
            await page.getByRole('button', { name: 'All Resource Types' }).click();

            await expect(page.getByPlaceholder('Search types...')).toBeVisible();
        });

        test('selecting a type updates URL with type parameter', async ({ page }) => {
            await openFilterSection(page, 'Resource Type');
            await page.getByRole('button', { name: 'All Resource Types' }).click();

            const options = page.getByRole('option');
            await expect(options.first()).toBeVisible();
            await options.first().click();

            await expect(page).toHaveURL(/[?&]type(?:%5B\d*%5D|\[\d*\])=/i);
        });

        test('clearing selection removes type from URL', async ({ page }) => {
            await openPortal(page, '/doi-search?type[]=dataset');
            await openFilterSection(page, 'Resource Type');

            const trigger = page.getByRole('button', { name: /^\d+ selected/ });
            await expect(trigger).toBeVisible();
            await trigger.click();

            const clearButton = page.getByRole('button', { name: /clear filter/i });
            await expect(clearButton).toBeVisible();
            await clearButton.click();

            await expect
                .poll(() => {
                    const url = new URL(page.url());
                    return url.searchParams.has('type[]') || url.searchParams.has('type');
                })
                .toBe(false);
        });
    });

    test.describe('Datacenter Filter', () => {
        test('scrolls only the sidebar and shows filtered results from the top', async ({ page }) => {
            await page.setViewportSize({ width: 1280, height: 500 });

            const sidebar = page.getByTestId('portal-filter-sidebar');
            const sidebarViewport = sidebar.locator(':scope > [data-slot="scroll-area"] > [data-slot="scroll-area-viewport"]');

            await openFilterSection(page, 'Datacenter');
            const trigger = page.getByRole('button', { name: 'All Datacenters' });
            await trigger.scrollIntoViewIfNeeded();

            await sidebarViewport.evaluate((element) => element.scrollTo(0, element.scrollHeight));
            await expect.poll(() => sidebarViewport.evaluate((element) => element.scrollTop)).toBeGreaterThan(0);
            expect(await page.evaluate(() => window.scrollY)).toBe(0);

            await trigger.click();
            const option = page.getByRole('option', { name: /Playwright: Portal Datacenter/ });
            await expect(option).toBeVisible();
            await option.click();

            await expect(page).toHaveURL(/datacenter/);
            await expect(page.getByTestId('portal-results-list').first()).toBeVisible();
            await expect.poll(() => page.evaluate(() => window.scrollY)).toBe(0);
        });
    });

    test.describe('Map Interaction', () => {
        test('map can be collapsed and expanded', async ({ page }) => {
            await page.getByRole('button', { name: 'Collapse map' }).click();
            await expect(page.getByTestId('portal-map-container')).toHaveCount(0);

            await page.getByRole('button', { name: 'Show map', exact: true }).click();
            await expect(page.getByTestId('portal-map-container')).toBeVisible();
            await expect(page.locator('.leaflet-container').first()).toBeVisible();
        });

        test('map shows OpenStreetMap attribution', async ({ page }) => {
            await expect(page.getByRole('link', { name: 'OpenStreetMap' }).first()).toBeVisible();
        });
    });

    test.describe('Filter Sidebar Toggle', () => {
        test('sidebar can be collapsed', async ({ page }) => {
            await page.getByRole('button', { name: 'Collapse filters' }).click();

            await expect(searchInput(page)).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Expand filters' })).toBeVisible();
        });

        test('collapsed sidebar can be expanded', async ({ page }) => {
            await page.getByRole('button', { name: 'Collapse filters' }).click();
            await page.getByRole('button', { name: 'Expand filters' }).click();

            await expect(searchInput(page)).toBeVisible();
        });
    });

    test.describe('URL State Persistence', () => {
        test('filters are restored from URL on page load', async ({ page }) => {
            await openPortal(page, '/doi-search?q=climate&type[]=dataset&page=1');

            await expect(searchInput(page)).toHaveValue('climate');
            await openFilterSection(page, 'Resource Type');
            await expect(page.getByRole('button', { name: /^\d+ selected/ })).toBeVisible();
        });

        test('URL state survives page refresh', async ({ page }) => {
            await searchInput(page).fill('test');
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await expect(page).toHaveURL(/q=test/);

            await page.reload({ waitUntil: 'domcontentloaded', timeout: 60_000 });

            await expect(searchInput(page)).toHaveValue('test');
        });
    });

    test.describe('Results Display', () => {
        test('results show resource cards or empty state', async ({ page }) => {
            const resultsArea = page.getByTestId('portal-results-list').first();
            const emptyState = page.getByText(/no results found/i);

            await expect(async () => {
                const hasResults = await resultsArea.isVisible();
                const hasEmpty = await emptyState.isVisible();
                expect(hasResults || hasEmpty).toBe(true);
            }).toPass();
        });

        test('pagination appears when there are multiple pages', async ({ page }) => {
            const resultsText = page.getByText(/showing \d+-\d+ of [\d,.]+ results/i).first();

            if (await resultsText.isVisible()) {
                const text = await resultsText.textContent();
                const match = text?.match(/of ([\d,.]+) results/i);
                const total = match ? Number.parseInt(match[1].replace(/[,.]/g, ''), 10) : 0;

                if (total > 20) {
                    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeVisible();
                }
            }
        });

        test('keeps pagination visible while the result cards scroll inside the workspace', async ({ page }) => {
            await page.setViewportSize({ width: 1280, height: 500 });
            await openPortal(page);

            const workspace = page.getByTestId('portal-workspace');
            const results = page.getByTestId('portal-results-list').first();
            const resultsViewport = results.locator('[data-slot="scroll-area-viewport"]');
            const nextButton = results.getByRole('button', { name: 'Next', exact: true });

            await expect(results).toBeVisible();
            await expect(nextButton).toBeVisible();
            expect(await resultsViewport.evaluate((element) => element.scrollHeight > element.clientHeight)).toBe(true);

            await resultsViewport.evaluate((element) => element.scrollTo(0, element.scrollHeight));
            await expect.poll(() => resultsViewport.evaluate((element) => element.scrollTop)).toBeGreaterThan(0);
            expect(await page.evaluate(() => window.scrollY)).toBe(0);

            const workspaceBox = await workspace.boundingBox();
            const nextBox = await nextButton.boundingBox();
            expect(workspaceBox).not.toBeNull();
            expect(nextBox).not.toBeNull();
            expect(nextBox!.y).toBeGreaterThanOrEqual(workspaceBox!.y);
            expect(nextBox!.y + nextBox!.height).toBeLessThanOrEqual(workspaceBox!.y + workspaceBox!.height);

            await nextButton.click();
            await expect(page).toHaveURL(/[?&]page=2(?:&|$)/);
        });
    });

    test.describe('Accessibility', () => {
        test('page has proper heading structure', async ({ page }) => {
            const heading = page.getByRole('heading', { level: 1, name: 'GFZ Data Services Portal' });
            await expect(heading).toBeVisible();
        });

        test('search input has associated label', async ({ page }) => {
            await expect(searchInput(page)).toBeVisible();
        });

        test('resource type filter is accessible via button', async ({ page }) => {
            await openFilterSection(page, 'Resource Type');
            await expect(page.getByRole('button', { name: 'All Resource Types' })).toBeVisible();
        });

        test('interactive elements are keyboard accessible', async ({ page }) => {
            const input = searchInput(page);
            await input.focus();
            await page.keyboard.type('keyboard test');

            await expect(input).toHaveValue('keyboard test');
        });
    });
});

test.describe('IGSN Portal Page', () => {
    test.beforeEach(async ({ page }) => {
        await openPortal(page, '/igsn-search');
    });

    test('uses the IGSN title and omits the resource type filter', async ({ page }) => {
        await expect(page).toHaveTitle(/IGSN Portal/);
        await expect(page.getByRole('button', { name: 'Resource Type', exact: true })).toHaveCount(0);
    });

    test('keeps searches inside the IGSN portal', async ({ page }) => {
        await searchInput(page).fill('sample');
        await page.getByRole('button', { name: 'Search', exact: true }).click();

        await expect(page).toHaveURL(/\/igsn-search\?q=sample/);
    });
});

for (const portal of [
    { path: '/doi-search', typeSlug: 'dataset', typeName: 'Dataset' },
    { path: '/igsn-search', typeSlug: 'physical-object', typeName: 'Physical Object' },
] as const) {
    test(`${portal.path} cluster navigation stays monotone and exposes colocated records`, async ({ page }) => {
        const requestedZooms: number[] = [];
        let releaseDelayedResponse: (() => void) | null = null;
        let delayedResponseReleased = false;

        await page.route(`**${portal.path}/map**`, async (route) => {
            const url = new URL(route.request().url());

            if (url.pathname.includes('/map/clusters/')) {
                expect(url.searchParams.has('zoom')).toBe(false);
                await route.fulfill({
                    contentType: 'application/json',
                    body: JSON.stringify({
                        schemaVersion: 1,
                        clusterId: 'z18:140812:37114',
                        total: 39,
                        members: Array.from({ length: 39 }, (_, index) => ({
                                kind: 'resource',
                                id: String(1263 + index),
                                position: { lat: 78.3901, lng: 14.976 },
                                bounds: { north: 78.3901, south: 78.3901, east: 14.976, west: 14.976 },
                                geometry: { type: 'point', latitude: 78.3901, longitude: 14.976 },
                                resource: {
                                    id: 1263 + index,
                                    identifier: `10.5880/issue-1263-${index + 1}`,
                                    title: index === 0 ? 'Issue 1263 colocated record' : `Colocated record ${index + 1}`,
                                    creators: [],
                                    resourceType: { slug: portal.typeSlug, name: portal.typeName },
                                    presentation:
                                        portal.typeSlug === 'physical-object'
                                            ? { dimension: 'material', key: 'rock', label: 'Rock', status: 'value' }
                                            : { dimension: 'resource-type', key: 'dataset', label: 'Dataset', status: 'value' },
                                    igsn:
                                        portal.typeSlug === 'physical-object'
                                            ? { sampleType: 'Core', material: 'Rock', materialLabel: 'Rock' }
                                            : null,
                                    landingPageUrl: '/landing/issue-1263',
                                },
                            })),
                        pagination: { currentPage: 1, lastPage: 1, perPage: 50 },
                    }),
                });
                return;
            }

            const zoom = Number(url.searchParams.get('zoom'));
            requestedZooms.push(zoom);
            if (zoom === 8 && !delayedResponseReleased) {
                await new Promise<void>((resolve) => {
                    releaseDelayedResponse = resolve;
                });
            }

            await route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({
                    schemaVersion: 3,
                    features: [
                        {
                            kind: 'cluster',
                            id: `z${zoom}:140812:37114`,
                            position: { lat: 78.3901, lng: 14.976 },
                            bounds: { north: 90, south: -90, east: 180, west: -180 },
                            navigationBounds: { north: 78.391, south: 78.389, east: 14.977, west: 14.975 },
                            count: 39,
                            resourceTypeCounts: { [portal.typeSlug]: 39 },
                            composition: {
                                dimension: portal.typeSlug === 'physical-object' ? 'material' : 'resource-type',
                                counts: { [portal.typeSlug === 'physical-object' ? 'rock' : 'dataset']: 39 },
                            },
                        },
                    ],
                    meta: {
                        requestedZoom: zoom,
                        effectiveZoom: zoom,
                        visibleLocations: 39,
                        returnedFeatures: 1,
                        totalLocations: 39,
                        extent: null,
                        coarsened: false,
                        visualizationDimension: portal.typeSlug === 'physical-object' ? 'material' : 'resource-type',
                    },
                }),
            });
        });

        await openPortal(page, portal.path);
        const map = page.locator('.leaflet-container').first();
        const mapPane = map.locator('.leaflet-map-pane');
        const cluster = () => map.locator('.portal-pie-cluster').first();
        const zoomIn = map.locator('.leaflet-control-zoom-in');
        const zoomOut = map.locator('.leaflet-control-zoom-out');

        await expect(cluster()).toBeVisible();
        await expect(cluster()).toHaveClass(/leaflet-interactive/);

        let currentZoom = requestedZooms.at(-1)!;
        for (let adjustment = 0; currentZoom !== 4 && adjustment < 18; adjustment++) {
            const targetZoom = currentZoom < 4 ? currentZoom + 1 : currentZoom - 1;
            await expect(mapPane).not.toHaveClass(/leaflet-zoom-anim/);
            await (currentZoom < 4 ? zoomIn : zoomOut).click();
            await expect(mapPane).not.toHaveClass(/leaflet-zoom-anim/);
            await expect.poll(() => requestedZooms.at(-1)).toBe(targetZoom);
            currentZoom = targetZoom;
        }
        expect(currentZoom).toBe(4);

        await cluster().dispatchEvent('click');
        await expect.poll(() => requestedZooms.at(-1)).toBe(8);
        await expect(cluster()).not.toHaveClass(/leaflet-interactive/);
        const requestsWhileStale = requestedZooms.length;
        await cluster().dispatchEvent('click');
        expect(requestedZooms).toHaveLength(requestsWhileStale);

        delayedResponseReleased = true;
        releaseDelayedResponse?.();
        await expect(cluster()).toHaveClass(/leaflet-interactive/);

        const navigationZooms = [4, 8];
        currentZoom = 8;
        for (let click = 0; currentZoom < 18 && click < 8; click++) {
            const previousZoom = currentZoom;
            await cluster().click();
            await expect.poll(() => requestedZooms.at(-1)).toBeGreaterThan(previousZoom);
            currentZoom = requestedZooms.at(-1)!;
            expect(currentZoom).toBeLessThanOrEqual(Math.min(18, previousZoom + 4));
            navigationZooms.push(currentZoom);
            await expect(cluster()).toHaveClass(/leaflet-interactive/);
        }

        expect(currentZoom).toBe(18);
        expect(navigationZooms.slice(0, 4)).toEqual([4, 8, 12, 16]);

        await cluster().click();
        const members = page.getByRole('region', { name: 'Records at this map location' });
        await expect(members).toBeVisible();
        await expect(members.getByText('Issue 1263 colocated record')).toBeVisible();
        await expect(members.getByRole('link', { name: /view details/i }).first()).toHaveAttribute('href', '/landing/issue-1263');
    });
}

test('the former shared search route is no longer available', async ({ page }) => {
    const response = await page.goto('/search', { waitUntil: 'domcontentloaded' });

    expect(response).not.toBeNull();
    expect(response!.status()).toBe(404);
});
