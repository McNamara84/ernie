import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

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
                .poll(async () => {
                    const response = await request.get('/resources/js/pages/auth/login.tsx');
                    const status = response.status();
                    const contentType = response.headers()['content-type'] ?? '';
                    return `${status}:${contentType}`;
                }, {
                    timeout: 60_000,
                    intervals: [500, 1000, 2000, 5000],
                })
                .toMatch(/^200:.*javascript/i);
        }

        await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 60_000 });
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

        // Open landing page setup modal for the first visible resource
        const setupLandingPageButton = page.getByRole('button', { name: /setup landing page for resource/i }).first();
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

        const [previewPage] = await Promise.all([
            context.waitForEvent('page'),
            previewButton.click(),
        ]);

        await previewPage.waitForLoadState('domcontentloaded');
        // Verify the preview URL format.
        // Depending on environment/config, the preview can open either:
        // - the internal preview route: /resources/{id}/landing-page/preview
        // - semantic URL with preview token: /{doi}/{slug}?preview=... or /draft-{id}/{slug}?preview=...
        //
        // The regex matches route constraints from web.php exactly:
        // - DOI prefix: 10.NNNN/suffix (lowercase alphanumeric with dots, slashes, hyphens)
        // - Slug: lowercase alphanumeric with hyphens only [a-z0-9-]+
        // Note: DOI suffix can contain uppercase in theory, but GFZ uses lowercase.
        const previewUrl = previewPage.url();
        const slugPattern = '[a-z0-9-]+';
        // DOI pattern matches web.php: 10\.[0-9]+/[a-zA-Z0-9._/-]+
        const doiPattern = '10\\.\\d+/[a-zA-Z0-9._/-]+';
        const previewUrlRegex = new RegExp(
            `/(resources/\\d+/landing-page/preview|${doiPattern}/${slugPattern}\\?preview=|draft-\\d+/${slugPattern}\\?preview=)`
        );
        const isValidPreviewUrl = previewUrlRegex.test(previewUrl);
        expect(
            isValidPreviewUrl, 
            `Expected preview URL to match pattern: /resources/{id}/landing-page/preview or /{doi}/{slug}?preview= or /draft-{id}/{slug}?preview=. Got: ${previewUrl}`
        ).toBeTruthy();

        // For semantic URLs, verify the preview token parameter is actually present
        if (!previewUrl.includes('/landing-page/preview')) {
            expect(previewUrl).toContain('?preview=');
        }

        // The default template shows this banner in preview mode
        await expect(previewPage.getByText('Preview Mode')).toBeVisible({ timeout: 15000 });

        // Sanity: should not be a generic Laravel error page
        await expect(previewPage.getByText(/server error|whoops/i)).not.toBeVisible();
    });
});
