import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

async function gotoWithLocalTlsRetry(page: Page, path: string): Promise<void> {
    const navigate = () => page.goto(path, { waitUntil: 'domcontentloaded' as const, timeout: 60_000 });

    await navigate().catch((error: unknown) => {
        if (!(error instanceof Error) || !error.message.includes('SSL connect error')) {
            throw error;
        }

        return navigate();
    });
}

test.describe('Landing Page Preview (Setup Modal)', () => {
    test.beforeEach(async ({ page, request }) => {
        page.on('pageerror', (error) => {
            // Keep output minimal: only unexpected runtime errors
            console.error('Page error:', error);
        });

        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                console.error('Console error:', msg.text());
            }
        });

        // Vite can take a while to boot in Docker (Wayfinder generation, warmup).
        // If tests start while Vite is still starting, JS/CSS requests may 502 and the page won't render.
        // In CI we often serve built assets (no Vite dev server) where `/@vite/client` is expected to be 404.
        // We treat 200 (Vite dev) OR 404 (built assets) as "ready" and keep retrying on 502/503.
        const assetMode = await (async () => {
            const start = Date.now();
            const intervals = [500, 1000, 2000, 5000];
            let attempt = 0;

            while (Date.now() - start < 60_000) {
                const response = await request.get('/@vite/client');
                const status = response.status();

                if (status === 200) {
                    return 'vite';
                }

                if (status === 404) {
                    return 'built';
                }

                if (status !== 502 && status !== 503) {
                    return `unexpected:${status}`;
                }

                const waitMs = intervals[Math.min(attempt, intervals.length - 1)];
                attempt += 1;
                await new Promise((resolve) => setTimeout(resolve, waitMs));
            }

            return 'timeout';
        })();

        expect(assetMode).toMatch(/^(vite|built)$/);

        // Extra safety for Docker/Vite mode: ensure the actual app modules are served with a JS MIME type.
        // This avoids flakiness where Vite is up but module requests still return empty/incorrect content-type.
        if (assetMode === 'vite') {
            await expect
                .poll(
                    async () => {
                        const response = await request.get('/resources/js/pages/auth/login.tsx');
                        const status = response.status();
                        const contentType = response.headers()['content-type'] ?? '';
                        return `${status}:${contentType}`;
                    },
                    {
                        timeout: 60_000,
                        intervals: [500, 1000, 2000, 5000],
                    },
                )
                .toMatch(/^200:.*javascript/i);
        }

        await gotoWithLocalTlsRetry(page, '/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 30000, waitUntil: 'domcontentloaded' });
    });

    test('automatically switches between request and download previews', async ({ page, context }) => {
        const resourcesPage = new ResourcesPage(page);
        await gotoWithLocalTlsRetry(page, '/resources');
        await resourcesPage.search('Playwright: Curation Resource (no landing page)');
        const row = resourcesPage.resourceTable.locator('tbody tr').filter({ hasText: 'Playwright: Curation Resource (no landing page)' }).first();
        await expect(row).toBeVisible();
        await row.getByRole('checkbox').click();
        const setup = page.getByTestId('resources-action-setup-landing-page');
        if (!(await setup.isVisible())) await page.getByTestId('resources-actions-menu-trigger').click();
        await setup.click();
        const dialog = page.getByRole('dialog');
        const preview = async () => {
            const [tab] = await Promise.all([context.waitForEvent('page'), dialog.getByRole('button', { name: /^Preview$/ }).click()]);
            await expect(tab.getByText('Preview Mode')).toBeVisible();
            return tab;
        };

        await expect(dialog.getByRole('button', { name: 'Add Download URL' })).toBeVisible();
        const emptyPreview = await preview();
        await expect(emptyPreview.getByTestId('data-request-section')).toBeVisible();
        await emptyPreview.close();

        await dialog.getByRole('button', { name: 'Add Download URL' }).click();
        await expect(dialog.getByRole('combobox', { name: 'Download URL', exact: true })).toBeFocused();
        await dialog.getByRole('combobox', { name: 'Download URL', exact: true }).fill('https://example.org/issue-1363.zip');
        await dialog.getByLabel('Button label', { exact: true }).fill('Issue 1363 archive');
        const downloadPreview = await preview();
        await expect(downloadPreview.getByRole('link', { name: 'Issue 1363 archive' })).toHaveAttribute('href', 'https://example.org/issue-1363.zip');
        await expect(downloadPreview.getByTestId('data-request-section')).toHaveCount(0);
        await downloadPreview.close();

        await dialog.getByRole('button', { name: 'Remove download URL' }).click();
        await expect(dialog.getByRole('button', { name: 'Add Download URL' })).toBeFocused();
        const removedPreview = await preview();
        await expect(removedPreview.getByTestId('data-request-section')).toBeVisible();
        await removedPreview.close();
        await dialog.getByRole('button', { name: 'Cancel' }).click();
    });

    test('reorders download URL suggestions using the keyboard and drag handle', async ({ page }) => {
        await gotoWithLocalTlsRetry(page, '/settings');
        await page.getByRole('button', { name: /Download URL suggestions/ }).click();
        const prefix = page.getByRole('textbox', { name: 'New download URL prefix' });
        for (const value of ['https://issue-1363.example/first', 'https://issue-1363.example/second']) {
            await prefix.fill(value);
            await page.getByRole('button', { name: 'Add prefix', exact: true }).click();
        }
        const list = page.getByRole('list', { name: 'Download URL suggestions' });
        const inputs = list.getByRole('textbox');
        const count = await inputs.count();
        const handle = list.getByRole('button', { name: `Reorder suggestion ${count}`, exact: true });
        await handle.scrollIntoViewIfNeeded();
        const initialPosition = await handle.boundingBox();
        await handle.focus();
        await page.keyboard.press('Space');
        await expect(handle).toHaveAttribute('aria-pressed', 'true');
        await page.keyboard.press('ArrowUp');
        await expect.poll(async () => (await handle.boundingBox())?.y ?? Infinity).toBeLessThan(initialPosition!.y - 10);
        await page.keyboard.press('Space');
        await expect(inputs.nth(count - 2)).toHaveValue('https://issue-1363.example/second');

        const from = await list.getByRole('button', { name: `Reorder suggestion ${count - 1}`, exact: true }).boundingBox();
        const to = await list.getByRole('button', { name: `Reorder suggestion ${count}`, exact: true }).boundingBox();
        expect(from).not.toBeNull();
        expect(to).not.toBeNull();
        if (from && to) {
            await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
            await page.mouse.down();
            await page.mouse.move(to.x + to.width / 2, to.y + to.height / 2, { steps: 10 });
            await page.mouse.up();
        }
        await expect(inputs.nth(count - 1)).toHaveValue('https://issue-1363.example/second');
        await expect(page.getByRole('button', { name: /^Save changes$/i })).toBeEnabled();
        // Keep this interaction test independent of installation-wide settings.
    });

    test('opens session-based preview in a new tab without server error', async ({ page, context }) => {
        const resourcesPage = new ResourcesPage(page);
        await resourcesPage.goto();
        await resourcesPage.verifyOnResourcesPage();

        // This workflow assumes test data exists.
        // If the DB isn't seeded, the resources table won't render.
        if (await resourcesPage.noResourcesMessage.isVisible()) {
            throw new Error(
                'No resources found. Seed test data first (e.g. `docker exec ernie-app-dev php artisan db:seed --class=PlaywrightTestSeeder`).',
            );
        }

        await resourcesPage.verifyResourcesDisplayed();

        // Use the dedicated fixture without a saved landing page so Preview must
        // create the session-based preview that this test is intended to cover.
        const previewResourceTitle = 'Playwright: Curation Resource (no landing page)';
        await resourcesPage.search(previewResourceTitle);
        const previewResourceRow = resourcesPage.resourceTable.locator('tbody tr').filter({ hasText: previewResourceTitle }).first();
        await expect(previewResourceRow).toBeVisible();
        await previewResourceRow.getByRole('checkbox').click();
        await expect(page.getByText(/^1 resource selected$/)).toBeVisible();
        const setupLandingPageButton = page.getByTestId('resources-action-setup-landing-page');
        if (!(await setupLandingPageButton.isVisible().catch(() => false))) {
            await page.getByTestId('resources-actions-menu-trigger').click();
        }

        await expect(setupLandingPageButton).toBeVisible();
        await expect(setupLandingPageButton).toBeEnabled();
        await setupLandingPageButton.click();

        const dialog = page.getByRole('dialog');
        await expect(dialog).toBeVisible({ timeout: 15000 });
        await expect(dialog.getByText(/setup landing page/i)).toBeVisible();

        await expect(dialog.getByRole('button', { name: 'Add Download URL' })).toBeVisible();
        await expect(dialog.getByRole('checkbox', { name: 'No data available for automatic download' })).toHaveCount(0);

        // Clicking preview should create a session-based preview and open a new tab
        const previewButton = dialog.getByRole('button', { name: /^preview$/i });
        await expect(previewButton).toBeVisible();
        await expect(previewButton).toBeEnabled();

        const [previewPage] = await Promise.all([context.waitForEvent('page'), previewButton.click()]);

        const sessionPreviewPath = /^\/resources\/\d+\/landing-page\/preview$/;

        // Firefox can briefly expose the opener URL for the synchronously
        // created placeholder tab. Wait for the actual preview navigation
        // instead of treating any URL other than about:blank as final.
        await previewPage.waitForURL((url) => sessionPreviewPath.test(url.pathname), {
            timeout: 30_000,
            waitUntil: 'commit',
        });

        expect(new URL(previewPage.url()).pathname).toMatch(sessionPreviewPath);

        // The default template shows this banner in preview mode
        await expect(previewPage.getByText('Preview Mode')).toBeVisible({ timeout: 15000 });
        await expect(previewPage).toHaveTitle(/^Preview: .+ \| GFZ Data Services$/);
        await expect(previewPage.locator('meta[name="robots"]')).toHaveAttribute('content', 'noindex, nofollow');
        await expect(previewPage.locator('meta[name="robots"]')).toHaveAttribute('data-inertia', 'landing-page-robots');

        const previewUrlBeforeMetadataClick = previewPage.url();
        await previewPage.getByTitle('Download as DataCite XML').click();

        const metadataDialog = previewPage.getByRole('dialog', { name: 'Metadata download unavailable' });
        await expect(metadataDialog).toBeVisible();
        await expect(metadataDialog).toContainText('Dear User,');
        await expect(metadataDialog).toContainText(
            'The feature you requested is only available after your dataset has been registered with DataCite.',
        );
        expect(previewPage.url()).toBe(previewUrlBeforeMetadataClick);
        await metadataDialog.getByRole('button', { name: 'Close' }).last().click();
        await expect(metadataDialog).toBeHidden();

        await previewPage.getByRole('button', { name: 'Download ISO 19115-3:2023 metadata as XML' }).click();
        await expect(metadataDialog).toBeVisible();
        await expect(metadataDialog).toContainText(
            'The feature you requested is only available after your dataset has been registered with DataCite.',
        );
        expect(previewPage.url()).toBe(previewUrlBeforeMetadataClick);
        await metadataDialog.getByRole('button', { name: 'Close' }).last().click();
        await expect(metadataDialog).toBeHidden();

        const requestDataButton = previewPage.getByRole('button', { name: 'Request data via contact form' });
        if (await requestDataButton.isVisible()) {
            await requestDataButton.click();

            const contactDialog = previewPage.getByRole('dialog', { name: 'Contact Request' });
            await expect(contactDialog).toBeVisible();
            await contactDialog.getByLabel(/Your name/).fill('Preview E2E User');
            await contactDialog.getByLabel(/Your email/).fill('preview-e2e@example.com');
            await contactDialog.getByRole('textbox', { name: /Message/ }).fill('Please provide download information for this preview dataset.');

            const contactResponsePromise = previewPage.waitForResponse((response) => {
                const pathname = new URL(response.url()).pathname;

                return response.request().method() === 'POST' && pathname === `${new URL(previewPage.url()).pathname}/contact`;
            });
            const [contactResponse] = await Promise.all([
                contactResponsePromise,
                contactDialog.getByRole('button', { name: 'Send Message' }).click(),
            ]);

            expect(contactResponse.status()).toBe(200);
            await expect(contactDialog.getByText('Message sent successfully!')).toBeVisible();
        } else {
            await expect(
                previewPage.getByText('A contact form is currently unavailable because no email recipient is available for this dataset.'),
            ).toBeVisible();
        }

        // Sanity: should not be a generic Laravel error page
        await expect(previewPage.getByText(/server error|whoops/i)).not.toBeVisible();
    });
});
