import { expect, type Page, test } from '@playwright/test';

import type { PortalBasePath, PortalPageProps, PortalResource } from '../../../resources/js/types/portal';

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

async function openFilterSection(page: Page, name: 'Resource Type' | 'Datacenter' | 'Location') {
    const trigger = page.getByRole('button', { name: new RegExp(`^${name}(?: \\d+)?$`) });
    await trigger.scrollIntoViewIfNeeded();

    if ((await trigger.getAttribute('data-state')) !== 'open') {
        await trigger.click();
    }

    await expect(trigger).toHaveAttribute('data-state', 'open');
}

async function expectFilterLayout(page: Page, expectedValues: readonly string[]) {
    const items = page.getByTestId('portal-filter-sidebar').locator('[data-accordion-value]');

    await expect(items).toHaveCount(expectedValues.length);
    expect(await items.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-accordion-value')))).toEqual(expectedValues);
    expect(await items.evaluateAll((elements) => elements.map((element) => element.getAttribute('data-state')))).toEqual(
        expectedValues.map(() => 'closed'),
    );
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

        test('orders DOI filters and keeps every group collapsed by default', async ({ page }) => {
            await expectFilterLayout(page, ['datacenter', 'resource-type', 'geographic', 'thesaurus', 'temporal']);
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

            await page.evaluate(() => {
                document.documentElement.dataset.playwrightInertiaFinished = 'false';
                document.addEventListener(
                    'inertia:finish',
                    () => {
                        document.documentElement.dataset.playwrightInertiaFinished = 'true';
                    },
                    { once: true },
                );
            });
            await page.getByRole('button', { name: 'Search', exact: true }).click();
            await expect(page).toHaveURL(/q=something/);
            await expect(page.locator('html')).toHaveAttribute('data-playwright-inertia-finished', 'true');

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
            const search = sidebar.getByPlaceholder('Search datacenters...');
            const checkbox = sidebar.getByRole('checkbox', { name: /Select Playwright: Portal Datacenter/ });

            await expect(search).toBeVisible();
            await expect(page.getByRole('button', { name: 'All Datacenters' })).toHaveCount(0);

            await sidebarViewport.evaluate((element) => element.scrollTo(0, element.scrollHeight));
            await expect.poll(() => sidebarViewport.evaluate((element) => element.scrollTop)).toBeGreaterThan(0);
            expect(await page.evaluate(() => window.scrollY)).toBe(0);

            await expect(checkbox).toBeVisible();
            await checkbox.click();

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

        test('map shows OpenFreeMap, OpenMapTiles, and OpenStreetMap attribution', async ({ page }) => {
            await expect(page.getByRole('link', { name: /OpenFreeMap/ }).first()).toBeVisible();
            await expect(page.getByRole('link', { name: /OpenMapTiles/ }).first()).toBeVisible();
            await expect(page.getByRole('link', { name: /OpenStreetMap/ }).first()).toBeVisible();
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
            const resourceTypeSection = page.getByTestId('portal-filter-sidebar').locator('[data-accordion-value="resource-type"]');
            await expect(resourceTypeSection).toHaveAttribute('data-state', 'open');
            await expect(resourceTypeSection.getByRole('button', { name: /^\d+ selected/ })).toBeVisible();
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

        test('keeps pagination visible while the result cards scroll inside the workspace', async ({ page }) => {
            await page.setViewportSize({ width: 1280, height: 500 });
            await openPortal(page);

            const workspace = page.getByTestId('portal-workspace');
            const results = page.getByTestId('portal-results-list').first();
            const resultsViewport = results.locator('[data-slot="scroll-area-viewport"]');
            const nextButton = results.getByRole('button', { name: 'Next', exact: true });

            await expect(results).toBeVisible();
            // The asynchronous count adds page-number buttons and moves Next.
            await expect(results.getByRole('button', { name: '2', exact: true })).toBeVisible();
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
            await expect(results.getByRole('button', { name: '2', exact: true })).toBeInViewport();
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

test.describe('Numbered result pages', () => {
    const query = 'pagination-fixture';

    async function provideResults(page: Page, path: PortalBasePath) {
        const isIgsn = path === '/igsn-search';
        let perPage: number;
        const resources: PortalResource[] = Array.from({ length: 45 }, (_, i) => ({
            id: i + 1,
            title: `Result ${i + 1}`,
            creators: [],
            searchMatches: [],
            doi: null,
            year: null,
            resourceType: isIgsn ? 'Physical Object' : 'Dataset',
            resourceTypeSlug: isIgsn ? 'physical-object' : 'dataset',
            isIgsn,
            geoLocations: [],
            landingPageUrl: null,
        }));
        await page.route(
            (url) => url.pathname === path && url.searchParams.get('q') === query,
            async (route) => {
                const response = await route.fetch();
                const payload: { props: PortalPageProps } = await response.json();
                const { pagination } = payload.props;
                perPage = pagination.per_page;
                const offset = (pagination.current_page - 1) * perPage;
                payload.props.resources = resources.slice(offset, offset + perPage);
                pagination.from = offset + 1;
                pagination.to = offset + payload.props.resources.length;
                pagination.has_more = pagination.to < resources.length;
                await route.fulfill({ response, json: payload });
            },
        );
        await page.route(
            (url) => url.pathname === `${path}/count` && url.searchParams.get('q') === query,
            async (route) => {
                const response = await route.fetch();
                await route.fulfill({
                    response,
                    json: { ...(await response.json()), total: resources.length, last_page: Math.max(1, Math.ceil(resources.length / perPage)) },
                });
            },
        );
        return resources;
    }

    for (const path of ['/doi-search', '/igsn-search'] as const) {
        test(`keeps numbered pages and the result count visible when browsing ${path}`, async ({ page }) => {
            await page.setViewportSize({ width: 1440, height: 900 });
            await openPortal(page, path);
            await provideResults(page, path);
            await searchInput(page).fill(query);
            await page.getByRole('button', { name: 'Search', exact: true }).click();

            const results = page.getByTestId('portal-results-list');
            const button = (name: string) => results.getByRole('button', { name, exact: true });
            const expectPage = async (current: number, from: number, to: number) => {
                await expect(results.getByText(`Showing ${from}-${to} of 45 results`)).toBeVisible();
                await expect(results.getByTestId('portal-result-title').first()).toHaveText(`Result ${from}`);
                for (const number of ['1', '2', '3']) {
                    await expect(button(number)).toBeVisible();
                }
                await expect(button(String(current))).toHaveAttribute('aria-current', 'page');
            };

            await expectPage(1, 1, 20);
            await expect(button('Previous')).toBeDisabled();
            await button('Next').click();
            await expectPage(2, 21, 40);
            await button('3').click();
            await expectPage(3, 41, 45);
            await expect(button('Next')).toBeDisabled();
            await openFilterSection(page, 'Location');
            await page.getByRole('switch', { name: 'Filter by map area' }).click();
            await expect(page).toHaveURL((url) => url.searchParams.get('map_filter') === '0' && url.searchParams.get('page') === '3');
            await button('Previous').click();
            await expectPage(2, 21, 40);
            await expect(page).toHaveURL((url) => url.searchParams.get('map_filter') === '0' && url.searchParams.get('page') === '2');
            await page.getByRole('switch', { name: 'Filter by map area' }).click();
            await expect(page).toHaveURL((url) => !url.searchParams.has('map_filter') && url.searchParams.get('q') === query && url.searchParams.get('page') === '2');
            await expectPage(2, 21, 40);
        });

        test(`updates the last page when repeating the same search on ${path}`, async ({ page }) => {
            const countedAt = Date.now();
            await page.clock.setFixedTime(countedAt);
            await page.setViewportSize({ width: 1440, height: 900 });
            await openPortal(page, path);
            const resources = await provideResults(page, path);
            const search = page.getByRole('button', { name: 'Search', exact: true });
            const results = page.getByTestId('portal-results-list');
            const thirdPage = results.getByRole('button', { name: '3', exact: true });
            await searchInput(page).fill(query);
            await search.click();
            await expect(results.getByText('Showing 1-20 of 45 results')).toBeVisible();
            await expect(thirdPage).toBeVisible();

            // Only later pages change, so Inertia can reuse every result and pagination prop on page one.
            resources.splice(25);
            await page.clock.setFixedTime(countedAt + 30_000);
            await search.click();
            await expect(results.getByText('Showing 1-20 of 25 results')).toBeVisible();
            await expect(results.getByRole('button', { name: '2', exact: true })).toBeVisible();
            await expect(thirdPage).toHaveCount(0);
        });
    }
});

test.describe('IGSN Portal Page', () => {
    test.beforeEach(async ({ page }) => {
        await openPortal(page, '/igsn-search');
    });

    test('uses the IGSN title and omits the resource type filter', async ({ page }) => {
        await expect(page).toHaveTitle(/IGSN Portal/);
        await expect(page.getByRole('button', { name: 'Resource Type', exact: true })).toHaveCount(0);
    });

    test('orders IGSN filters and keeps every group collapsed by default', async ({ page }) => {
        await expectFilterLayout(page, [
            'datacenter',
            'sample-type',
            'material',
            'classification',
            'geological-age',
            'geological-unit',
            'geographic',
            'temporal',
        ]);
    });

    test('opens an IGSN group when its URL filter is active', async ({ page }) => {
        await openPortal(page, '/igsn-search?sample_types[]=Hole');

        const sampleTypeSection = page.getByTestId('portal-filter-sidebar').locator('[data-accordion-value="sample-type"]');
        await expect(sampleTypeSection).toHaveAttribute('data-state', 'open');
        await expect(sampleTypeSection.getByRole('checkbox', { name: 'Select Hole' })).toBeChecked();
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
    test(`${portal.path} follows the map from worldwide search and restores the default on reset`, async ({ page }) => {
        await page.setViewportSize({ width: 1440, height: 900 });
        await openPortal(page, portal.path);
        await openFilterSection(page, 'Location');
        const toggle = page.getByRole('switch', { name: 'Filter by map area' });
        const north = page.getByRole('spinbutton', { name: 'North latitude' });
        await expect(toggle).toBeChecked();
        await expect(north).toHaveValue('90.0000');
        await expect(page.getByRole('button', { name: 'Clear all filters' })).toHaveCount(0);

        await page.locator('.leaflet-control-zoom-in').click();
        await expect(page).toHaveURL((url) => url.searchParams.has('north'));
        await north.fill('53');
        await page.getByRole('spinbutton', { name: 'South latitude' }).fill('52');
        await page.getByRole('spinbutton', { name: 'West longitude' }).fill('13');
        await page.getByRole('spinbutton', { name: 'East longitude' }).fill('14');
        await page.getByRole('button', { name: 'Apply', exact: true }).click();
        await expect(page).toHaveURL((url) => url.searchParams.get('north') === '53.000000');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(north).toHaveValue('53.0000');
        await page.getByRole('button', { name: 'Clear', exact: true }).click();
        await expect(page).toHaveURL((url) => url.search === '?map_filter=0');
        await page.reload({ waitUntil: 'domcontentloaded' });
        await openFilterSection(page, 'Location');
        await expect(toggle).not.toBeChecked();
        await page.getByRole('button', { name: 'Clear all filters' }).click();
        await expect(page).toHaveURL((url) => url.pathname === portal.path && url.search === '');
        await openFilterSection(page, 'Location');
        await expect(toggle).toBeChecked();
        await expect(north).toHaveValue('90.0000');
    });

    test(`${portal.path} opens a saved regional map from the mobile results tab`, async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        const bounds = { north: '53', south: '52', east: '14', west: '13' };
        await openPortal(page, `${portal.path}?${new URLSearchParams(bounds)}`);
        await expect(page.getByTestId('portal-mobile-results-view')).toBeVisible();
        const mapRequest = page.waitForRequest((request) => {
            const url = new URL(request.url());
            return (
                url.pathname === `${portal.path}/map` &&
                Number(url.searchParams.get('zoom')) > 2 &&
                Number(url.searchParams.get('viewport[north]')) >= 53 &&
                Number(url.searchParams.get('viewport[south]')) <= 52 &&
                Number(url.searchParams.get('viewport[west]')) <= 13 &&
                Number(url.searchParams.get('viewport[east]')) >= 14
            );
        });
        await page.getByTestId('portal-mobile-map-tab').click();
        await mapRequest;
        await page.getByTestId('portal-mobile-results-tab').click();
        await expect.poll(() => Object.fromEntries(new URL(page.url()).searchParams)).toEqual(bounds);
    });

    test(`${portal.path} cluster navigation stays monotone and exposes colocated records`, async ({ page }) => {
        const requestedZooms: number[] = [];
        const delayedResponseControl: { release?: () => void } = {};
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
                    delayedResponseControl.release = resolve;
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

        // Recording the request does not mean its replacement clusters are ready.
        await expect(mapPane).not.toHaveClass(/leaflet-zoom-anim/);
        await expect(map.locator('..')).toHaveAttribute('aria-busy', 'false');
        await expect(cluster()).toHaveClass(/leaflet-interactive/);
        await cluster().dispatchEvent('click');
        await expect.poll(() => requestedZooms.at(-1)).toBe(8);
        await expect(cluster()).not.toHaveClass(/leaflet-interactive/);
        const requestsWhileStale = requestedZooms.length;
        await cluster().dispatchEvent('click');
        expect(requestedZooms).toHaveLength(requestsWhileStale);

        delayedResponseReleased = true;
        expect(delayedResponseControl.release).toBeDefined();
        delayedResponseControl.release?.();
        await expect(mapPane).not.toHaveClass(/leaflet-zoom-anim/);
        await expect(map.locator('..')).toHaveAttribute('aria-busy', 'false');
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
            await expect(mapPane).not.toHaveClass(/leaflet-zoom-anim/);
            await expect(map.locator('..')).toHaveAttribute('aria-busy', 'false');
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
