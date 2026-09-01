import { expect, type Page, test } from '@playwright/test';

/**
 * Portal E2E Tests
 *
 * Tests for the public data portal page with search, filters, map and results.
 * The portal is publicly accessible (no login required).
 */

const searchInput = (page: Page) => page.getByRole('combobox', { name: 'Search' });

async function openPortal(page: Page, path = '/search') {
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
            await openPortal(page, '/search?type[]=dataset');
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
            await openPortal(page, '/search?q=climate&type[]=dataset&page=1');

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
