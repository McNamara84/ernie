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

        // Sanity: should not be a generic Laravel error page
        await expect(previewPage.getByText(/server error|whoops/i)).not.toBeVisible();
    });
});
