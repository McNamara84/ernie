import { expect, type Page, type Response, test } from '@playwright/test';

import { loginAsTestUser } from '../helpers/test-helpers';

// Use the existing PlaywrightTestSeeder resources; a page size of one exercises
// several real cursor transitions without creating or modifying resources.
const listingUrl = '/resources?search=Playwright&per_page=1';
const rows = (page: Page) => page.locator('[data-testid^="resources-row-checkbox-"]');

async function scrollToEnd(page: Page) {
    for (let slice = 0; slice < 20; slice++) {
        if (await page.getByText(/All resources have been loaded/).isVisible()) return;
        const count = await rows(page).count();
        await rows(page).last().scrollIntoViewIfNeeded();
        await expect
            .poll(async () => (await rows(page).count()) > count || (await page.getByText(/All resources have been loaded/).isVisible()))
            .toBe(true);
    }
    throw new Error('The seeded resource list did not reach its end.');
}

test('restores saved sorting and scrolls through real cursor pages without duplicates', async ({ page }) => {
    await loginAsTestUser(page);
    await page.evaluate(() => {
        localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'desc' }));
        localStorage.removeItem('ernie.resources.datacenter-filter.v1');
    });
    const responses: Response[] = [];
    page.on('response', (response) => {
        if (new URL(response.url()).pathname === '/resources/load-more') responses.push(response);
    });

    await page.goto(listingUrl);
    await expect(page).toHaveURL(
        (url) =>
            url.searchParams.get('sort_key') === 'id' &&
            url.searchParams.get('sort_direction') === 'desc' &&
            url.searchParams.get('per_page') === '1',
    );
    await expect(rows(page).first()).toBeVisible();
    await scrollToEnd(page);

    expect(responses.length).toBeGreaterThanOrEqual(2);
    for (const response of responses) {
        expect(response.status()).toBe(200);
        const params = new URL(response.url()).searchParams;
        expect(params.get('sort_key')).toBe('id');
        expect(params.get('sort_direction')).toBe('desc');
        expect(params.get('per_page')).toBe('1');
        expect(params.get('search')).toBe('Playwright');
        expect(params.get('cursor')).toBeTruthy();
    }
    const ids = await rows(page).evaluateAll((elements) => elements.map((element) => Number(element.getAttribute('data-testid')!.split('-').at(-1))));
    expect(ids.length).toBeGreaterThanOrEqual(3);
    expect(new Set(ids).size).toBe(ids.length);
    expect(ids).toEqual([...ids].sort((a, b) => b - a));
    await expect(page.getByText('Failed to load more resources', { exact: true })).toHaveCount(0);
});

test('honors explicit sorting and pauses a rejected cursor until the user reloads', async ({ page }) => {
    await loginAsTestUser(page);
    await page.evaluate(() => {
        localStorage.setItem('resources.sort-preference', JSON.stringify({ key: 'id', direction: 'asc' }));
        localStorage.removeItem('ernie.resources.datacenter-filter.v1');
    });
    let attempts = 0;
    let rejectCursor = true;
    await page.route('**/resources/load-more?*', async (route) => {
        attempts++;
        if (rejectCursor) {
            await route.fulfill({
                status: 422,
                json: { message: 'The cursor does not match the active filters and sorting.', errors: { cursor: ['Context mismatch'] } },
            });
        } else {
            await route.continue();
        }
    });

    await page.goto(`${listingUrl}&sort_key=updated_at&sort_direction=desc`);
    await expect(rows(page).first()).toBeVisible();
    const firstRow = await rows(page).first().getAttribute('data-testid');
    await rows(page).last().scrollIntoViewIfNeeded();
    await expect(page.getByRole('button', { name: 'Reload list', exact: true })).toBeVisible();
    expect(attempts).toBe(1);
    await expect(page).toHaveURL((url) => url.searchParams.get('sort_key') === 'updated_at' && url.searchParams.get('sort_direction') === 'desc');
    await rows(page).last().scrollIntoViewIfNeeded();
    // Observe a bounded interval with the real IntersectionObserver still seeing
    // the final row. The former implementation continuously retried during it.
    await page.waitForTimeout(500);
    expect(attempts).toBe(1);
    await expect(page.getByTestId(firstRow!)).toBeVisible();

    rejectCursor = false;
    const reload = page.waitForResponse((response) => new URL(response.url()).pathname === '/resources');
    await page.getByRole('button', { name: 'Reload list', exact: true }).click();
    const reloadResponse = await reload;
    expect(reloadResponse.status()).toBe(200);
    expect(Object.fromEntries(new URL(reloadResponse.url()).searchParams)).toEqual({
        sort_key: 'updated_at',
        sort_direction: 'desc',
        per_page: '1',
        search: 'Playwright',
    });
    await expect(page.getByRole('button', { name: 'Reload list', exact: true })).toHaveCount(0);
    await scrollToEnd(page);
    expect(attempts).toBeGreaterThanOrEqual(3);
    const ids = await rows(page).evaluateAll((elements) => elements.map((element) => element.getAttribute('data-testid')));
    expect(new Set(ids).size).toBe(ids.length);
});
