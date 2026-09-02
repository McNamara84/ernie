import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

const SEEDED_RESOURCE_DOI = '10.1234/playwright-published';
const EDITOR_RELOAD_MODAL_MARKER = '__playwrightEditorReloadModalSeen';

function waitForAccordionPreferenceUpdate(page: Page) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'PUT' && url.pathname === '/settings/curation-accordion';
    });
}

async function gotoWithLocalTlsRetry(page: Page, url: string) {
    for (let attempt = 1; attempt <= 2; attempt += 1) {
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded' });
            return;
        } catch (error) {
            if (attempt === 2) {
                throw error;
            }
        }
    }
}

// Editor Form Basic Tests
// Critical editor functionality is covered by xml-upload tests.
// These tests just verify basic form accessibility.

test.describe('Editor Form', () => {
    test('editor page requires authentication', async ({ page }) => {
        // Try to access editor without login
        await page.goto('/editor', { waitUntil: 'commit' });

        // Should redirect to login
        await expect(page).toHaveURL(/\/login/);
    });

    test('editor page is accessible after login', async ({ page }) => {
        // Login first
        await page.goto('/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { timeout: 15000 });

        // Navigate to editor
        await page.goto('/editor');

        // Should be accessible (even if empty without XML upload)
        await expect(page).toHaveURL(/\/editor/);
    });

    test('a license near the end of a large catalog can be found and selected', async ({ page }) => {
        const targetLicense = {
            id: 600,
            identifier: 'ZZZ-PLAYWRIGHT-600',
            name: 'ZZZ Playwright License 600',
            uri: 'https://example.test/licenses/zzz-playwright-600',
            scheme_uri: 'https://spdx.org/licenses/',
        };
        const licenses = [
            ...Array.from({ length: 599 }, (_, index) => ({
                id: index + 1,
                identifier: `PLAYWRIGHT-${String(index + 1).padStart(3, '0')}`,
                name: `Playwright License ${String(index + 1).padStart(3, '0')}`,
                uri: `https://example.test/licenses/${index + 1}`,
                scheme_uri: 'https://spdx.org/licenses/',
            })),
            targetLicense,
        ];

        await gotoWithLocalTlsRetry(page, '/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });
        await page.route('**/api/v1/licenses/ernie', async (route) => {
            await route.fulfill({ json: licenses });
        });

        await gotoWithLocalTlsRetry(page, '/editor');
        const licensesTrigger = page.locator('[data-slot="accordion-trigger"]', { hasText: /Licenses.*Rights/i });
        await expect(page.getByTestId('resource-info-section')).toBeVisible({ timeout: 30_000 });

        if ((await licensesTrigger.getAttribute('aria-expanded')) !== 'true') {
            await licensesTrigger.click();
        }

        const licenseSelect = page.getByTestId('license-select-0');
        await licenseSelect.click();
        const searchInput = page.getByPlaceholder('Search licenses...');
        await searchInput.fill(targetLicense.identifier);
        await page.getByRole('option', { name: targetLicense.name, exact: true }).click();

        await expect(licenseSelect).toContainText(targetLicense.name);
    });

    test('collapsing a form group preserves unsaved editor state without reloading the editor', async ({ page, context }) => {
        await gotoWithLocalTlsRetry(page, '/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });

        const resourcesPage = new ResourcesPage(page);
        await gotoWithLocalTlsRetry(page, '/resources');
        await expect(resourcesPage.heading).toBeVisible({ timeout: 30_000 });
        await resourcesPage.search(SEEDED_RESOURCE_DOI);

        const editorPagePromise = context.waitForEvent('page');
        await page.getByText('Playwright: Published Resource', { exact: true }).click();
        const editorPage = await editorPagePromise;
        const licensesTrigger = editorPage.locator('[data-slot="accordion-trigger"]', { hasText: /Licenses.*Rights/i });
        let initialExpanded: boolean | null = null;

        try {
            await editorPage.waitForURL((url) => url.pathname === '/editor' && /^\d+$/.test(url.searchParams.get('resourceId') ?? ''), {
                timeout: 30_000,
            });
            await expect(editorPage.getByTestId('resource-info-section')).toBeVisible({ timeout: 30_000 });
            await expect(editorPage.getByTestId('editor-loading-modal')).toHaveCount(0);
            await editorPage.waitForLoadState('networkidle').catch(() => undefined);

            initialExpanded = (await licensesTrigger.getAttribute('aria-expanded')) === 'true';
            if (!initialExpanded) {
                const expandPreferenceResponse = waitForAccordionPreferenceUpdate(editorPage);
                await licensesTrigger.click();
                expect((await expandPreferenceResponse).status()).toBe(204);
                await expect(licensesTrigger).toHaveAttribute('aria-expanded', 'true');
            }

            const unsavedTitle = `UNSAVED ACCORDION REGRESSION ${Date.now()}`;
            const titleInput = editorPage.getByTestId('main-title-input');
            await titleInput.fill(unsavedTitle);
            await expect(titleInput).toHaveValue(unsavedTitle);

            await editorPage.evaluate((marker) => {
                const monitoredWindow = window as typeof window & Record<string, boolean>;
                const resourceInfoSection = document.querySelector<HTMLElement>('[data-testid="resource-info-section"]');

                monitoredWindow[marker] = false;
                if (resourceInfoSection) {
                    resourceInfoSection.dataset.accordionReloadMarker = 'original';
                }

                new MutationObserver(() => {
                    if (document.querySelector('[data-testid="editor-loading-modal"]')) {
                        monitoredWindow[marker] = true;
                    }
                }).observe(document.body, { childList: true, subtree: true });
            }, EDITOR_RELOAD_MODAL_MARKER);

            const editorGetRequests: string[] = [];
            const recordEditorGet = (request: import('@playwright/test').Request) => {
                const url = new URL(request.url());

                if (request.method() === 'GET' && url.pathname === '/editor') {
                    editorGetRequests.push(request.url());
                }
            };
            editorPage.on('request', recordEditorGet);

            const collapsePreferenceResponse = waitForAccordionPreferenceUpdate(editorPage);
            await licensesTrigger.click();
            const preferenceResponse = await collapsePreferenceResponse;
            expect(preferenceResponse.status()).toBe(204);
            await editorPage.waitForTimeout(750);

            editorPage.off('request', recordEditorGet);
            await expect(licensesTrigger).toHaveAttribute('aria-expanded', 'false');
            await expect(titleInput).toHaveValue(unsavedTitle);
            await expect(editorPage.getByTestId('editor-loading-modal')).toHaveCount(0);
            expect(editorGetRequests).toEqual([]);
            expect(
                await editorPage.evaluate((marker) => {
                    const monitoredWindow = window as typeof window & Record<string, boolean>;
                    const resourceInfoSection = document.querySelector<HTMLElement>('[data-testid="resource-info-section"]');

                    return {
                        modalSeen: monitoredWindow[marker],
                        originalFormPreserved: resourceInfoSection?.dataset.accordionReloadMarker === 'original',
                    };
                }, EDITOR_RELOAD_MODAL_MARKER),
            ).toEqual({ modalSeen: false, originalFormPreserved: true });
        } finally {
            if (!editorPage.isClosed()) {
                const isExpanded = (await licensesTrigger.getAttribute('aria-expanded').catch(() => null)) === 'true';
                if (initialExpanded !== null && isExpanded !== initialExpanded) {
                    const restorePreferenceResponse = waitForAccordionPreferenceUpdate(editorPage);
                    await licensesTrigger.click();
                    await restorePreferenceResponse;
                }

                await editorPage.close();
            }
        }
    });
});
