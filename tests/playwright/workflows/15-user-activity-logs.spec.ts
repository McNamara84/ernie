import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { navigateWithTlsRetry } from '../helpers/navigation';

test('shows editor, landing-page and export activity with working dataset links', async ({ page }) => {
    test.setTimeout(240_000);
    page.setDefaultNavigationTimeout(60_000);
    const login = await page.request.get('/login');
    const csrf = (await login.text()).match(/name="csrf-token" content="([^"]+)"/)?.[1];
    expect(csrf).toBeTruthy();
    const signedIn = await page.request.post('/login', {
        form: { _token: csrf!, email: TEST_USER_EMAIL, password: TEST_USER_PASSWORD },
        maxRedirects: 0,
    });
    expect(signedIn.status()).toBe(302);
    const title = `Playwright activity ${Date.now()}`;
    await navigateWithTlsRetry(page, '/editor', { waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    await page.getByTestId('main-title-input').fill(title);
    const createdResponse = page.waitForResponse(
        (response) => response.url().endsWith('/editor/resources/draft') && response.request().method() === 'POST',
    );
    await page.getByTestId('save-draft-button').click();
    const created = await createdResponse;
    expect(created.ok()).toBeTruthy();
    const id = (await created.json()).resource.id as number;
    await expect(page.getByTestId('save-draft-button')).toBeEnabled();

    await page.getByTestId('main-title-input').fill(`${title} revised`);
    const updatedResponse = page.waitForResponse(
        (response) => response.url().endsWith('/editor/resources/draft') && response.request().method() === 'POST',
    );
    await page.getByTestId('save-draft-button').click();
    expect((await updatedResponse).ok()).toBeTruthy();
    await expect(page.getByTestId('save-draft-button')).toBeEnabled();

    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const headers = { 'X-CSRF-TOKEN': token!, Accept: 'application/json' };
    const endpoint = `/resources/${id}/landing-page`;
    expect((await page.request.post(endpoint, { headers, data: { template: 'default_gfz', is_published: false } })).ok()).toBeTruthy();
    expect((await page.request.put(endpoint, { headers, data: { ftp_url: 'https://example.org/activity.zip' } })).ok()).toBeTruthy();
    const exported = await page.request.post('/resources/batch-export', { headers, data: { ids: [id], format: 'datacite-json' } });
    expect(exported.ok()).toBeTruthy();
    expect(exported.headers()['content-type']).toContain('application/zip');

    await navigateWithTlsRetry(page, `/logs?level=info&search=${encodeURIComponent(title)}`, { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('Application Logs', { exact: true })).toBeVisible();
    const table = page.getByRole('table');
    await expect(table.getByText(/updated metadata in the Data Editor/)).toBeVisible();
    await expect(table.getByText(/changed the FTP URL/)).toBeVisible();
    await expect(table.getByText(/generated a metadata export/)).toBeVisible();
    const link = table.getByRole('link', { name: 'Open dataset', exact: true }).first();
    await expect(link).toHaveAttribute('href', `/editor?resourceId=${id}`);
    await link.click();
    await expect(page).toHaveURL(new RegExp(`/editor\\?resourceId=${id}$`));
    await expect(page.getByTestId('main-title-input')).toHaveValue(`${title} revised`, { timeout: 60_000 });
});
