import { expect, type Page, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';
import { ResourcesPage } from '../helpers/page-objects/ResourcesPage';

const SEEDED_RESOURCE_DOI = '10.1234/playwright-published';
const EDITOR_RELOAD_MODAL_MARKER = '__playwrightEditorReloadModalSeen';
const CONTROLLED_VOCABULARY_LABELS = [
    'Science Keywords',
    'Platforms',
    'Instruments',
    'MSL Vocabulary',
    'Chronostratigraphy',
    'GEMET',
    'Analytical Methods',
    'EuroSciVoc',
    'Simple Lithology',
] as const;

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

    test('keeps controlled vocabulary tabs readable at every responsive layout', async ({ page }) => {
        await gotoWithLocalTlsRetry(page, '/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });

        await page.route('**/api/v1/vocabularies/thesauri-availability', async (route) => {
            await route.fulfill({
                json: {
                    science_keywords: { available: true },
                    platforms: { available: true },
                    instruments: { available: true },
                    chronostratigraphy: { available: true },
                    gemet: { available: true },
                    analytical_methods: { available: true },
                    euroscivoc: { available: true },
                    msl_laboratories: { available: true },
                    simple_lithology: { available: true },
                },
            });
        });

        await page.route(/^https?:\/\/[^/]+\/vocabularies\/[^/?]+(?:\?.*)?$/, async (route) => {
            const pathname = new URL(route.request().url()).pathname;

            await route.fulfill({ json: pathname === '/vocabularies/msl' ? [] : { data: [] } });
        });

        await gotoWithLocalTlsRetry(page, '/editor');
        await expect(page.getByTestId('resource-info-section')).toBeVisible({ timeout: 30_000 });

        const freeKeywordsTrigger = page.locator('[data-slot="accordion-trigger"]', { hasText: 'Free Keywords' });
        if ((await freeKeywordsTrigger.getAttribute('aria-expanded')) !== 'true') {
            await freeKeywordsTrigger.click();
        }

        const freeKeywordsInput = page.getByTestId('free-keywords-tagify').locator('.tagify__input');
        await freeKeywordsInput.fill('EPOS');
        await freeKeywordsInput.press('Enter');
        await expect(page.getByTestId('free-keywords-tagify').locator('.tagify__tag-text').first()).toContainText('EPOS');

        const controlledVocabulariesTrigger = page.locator('[data-slot="accordion-trigger"]', {
            hasText: 'Controlled Vocabularies',
        });
        if ((await controlledVocabulariesTrigger.getAttribute('aria-expanded')) !== 'true') {
            await controlledVocabulariesTrigger.click();
        }

        const tabList = page.getByRole('tablist', { name: 'Controlled vocabularies' });
        await expect(tabList).toBeVisible();
        const tabs = tabList.getByRole('tab');
        await expect(tabs).toHaveCount(CONTROLLED_VOCABULARY_LABELS.length);
        await tabList.getByRole('tab', { name: 'Science Keywords', exact: true }).click();

        for (const label of CONTROLLED_VOCABULARY_LABELS) {
            await expect(tabList.getByRole('tab', { name: label, exact: true })).toHaveAccessibleName(label);
        }

        const readLayout = () =>
            tabList.evaluate((list) => {
                const tabElements = Array.from(list.querySelectorAll<HTMLElement>('[role="tab"]'));
                const listRect = list.getBoundingClientRect();
                const visibleContent = tabElements.map((tab) => {
                    const parts = [
                        tab.querySelector<HTMLElement>('.controlled-vocabulary-tab-icon'),
                        tab.querySelector<HTMLElement>('.controlled-vocabulary-tab-label'),
                    ].filter((part): part is HTMLElement => part !== null && getComputedStyle(part).display !== 'none');
                    const partRects = parts.map((part) => part.getBoundingClientRect());

                    return {
                        label: tab.getAttribute('aria-label') ?? '',
                        left: Math.min(...partRects.map((rect) => rect.left)),
                        right: Math.max(...partRects.map((rect) => rect.right)),
                        top: Math.min(...partRects.map((rect) => rect.top)),
                        bottom: Math.max(...partRects.map((rect) => rect.bottom)),
                        visibleLabel: getComputedStyle(tab.querySelector<HTMLElement>('.controlled-vocabulary-tab-label')!).display !== 'none',
                        visibleIcon: getComputedStyle(tab.querySelector<HTMLElement>('.controlled-vocabulary-tab-icon')!).display !== 'none',
                    };
                });
                const overlaps: string[] = [];

                for (let index = 0; index < visibleContent.length; index += 1) {
                    for (let nextIndex = index + 1; nextIndex < visibleContent.length; nextIndex += 1) {
                        const current = visibleContent[index];
                        const next = visibleContent[nextIndex];
                        const overlapsHorizontally = current.left < next.right - 0.5 && next.left < current.right - 0.5;
                        const overlapsVertically = current.top < next.bottom - 0.5 && next.top < current.bottom - 0.5;

                        if (overlapsHorizontally && overlapsVertically) {
                            overlaps.push(`${current.label} -> ${next.label}`);
                        }
                    }
                }

                const activeLabel = document.querySelector<HTMLElement>('.controlled-vocabulary-active-label');
                const activeLabelVisible = activeLabel !== null && getComputedStyle(activeLabel).display !== 'none';

                return {
                    viewportWidth: window.innerWidth,
                    documentWidth: document.documentElement.scrollWidth,
                    listClientWidth: list.clientWidth,
                    listScrollWidth: list.scrollWidth,
                    clippedContent: visibleContent
                        .filter((content) => content.left < listRect.left - 0.5 || content.right > listRect.right + 0.5)
                        .map((content) => content.label),
                    overlaps,
                    visibleIcons: visibleContent.filter((content) => content.visibleIcon).map((content) => content.label),
                    visibleLabels: visibleContent.filter((content) => content.visibleLabel).map((content) => content.label),
                    activeLabelVisible,
                    activeLabelText: activeLabelVisible ? (activeLabel?.textContent?.trim() ?? '') : '',
                };
            });

        const viewports = [
            { width: 1607, height: 900, mode: 'wide' },
            { width: 948, height: 700, mode: 'compact' },
            { width: 768, height: 700, mode: 'narrow' },
            { width: 393, height: 852, mode: 'narrow' },
            { width: 320, height: 568, mode: 'narrow' },
        ] as const;

        for (const viewport of viewports) {
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
            const layout = await readLayout();

            expect(layout.overlaps, `${viewport.width}px tab content overlaps`).toEqual([]);
            expect(layout.clippedContent, `${viewport.width}px tab content is clipped`).toEqual([]);
            expect(layout.listScrollWidth, `${viewport.width}px tab list scroll width`).toBeLessThanOrEqual(layout.listClientWidth);
            expect(layout.documentWidth, `${viewport.width}px document width`).toBeLessThanOrEqual(layout.viewportWidth);

            if (viewport.mode === 'wide') {
                expect(layout.visibleLabels).toEqual(CONTROLLED_VOCABULARY_LABELS);
                expect(layout.visibleIcons).toEqual([]);
                expect(layout.activeLabelVisible).toBe(false);
            } else if (viewport.mode === 'compact') {
                expect(layout.visibleLabels).toEqual(['Science Keywords']);
                expect(layout.visibleIcons).toEqual(CONTROLLED_VOCABULARY_LABELS);
                expect(layout.activeLabelVisible).toBe(false);
            } else {
                expect(layout.visibleLabels).toEqual([]);
                expect(layout.visibleIcons).toEqual(CONTROLLED_VOCABULARY_LABELS);
                expect(layout.activeLabelVisible).toBe(true);
                expect(layout.activeLabelText).toBe('Science Keywords');
            }
        }

        await page.setViewportSize({ width: 948, height: 700 });
        const scienceTab = tabList.getByRole('tab', { name: 'Science Keywords', exact: true });
        const platformsTab = tabList.getByRole('tab', { name: 'Platforms', exact: true });
        const analyticalMethodsTab = tabList.getByRole('tab', { name: 'Analytical Methods', exact: true });

        await expect(platformsTab).toHaveAttribute('title', 'Platforms');

        await analyticalMethodsTab.click();
        await expect(analyticalMethodsTab).toHaveAttribute('aria-selected', 'true');
        expect((await readLayout()).visibleLabels).toEqual(['Analytical Methods']);

        await scienceTab.focus();
        await page.keyboard.press('ArrowRight');
        await expect(platformsTab).toHaveAttribute('aria-selected', 'true');
        expect((await readLayout()).visibleLabels).toEqual(['Platforms']);

        await page.setViewportSize({ width: 393, height: 852 });
        await analyticalMethodsTab.click();
        await expect(page.getByTestId('controlled-vocabulary-active-label')).toContainText('Analytical Methods');

        await page.evaluate(() => document.documentElement.classList.add('font-large'));
        for (const viewport of [
            { width: 948, height: 700 },
            { width: 393, height: 852 },
            { width: 320, height: 568 },
        ]) {
            await page.setViewportSize(viewport);
            const layout = await readLayout();

            expect(layout.overlaps, `${viewport.width}px large-font tab content overlaps`).toEqual([]);
            expect(layout.clippedContent, `${viewport.width}px large-font tab content is clipped`).toEqual([]);
            expect(layout.listScrollWidth, `${viewport.width}px large-font tab list scroll width`).toBeLessThanOrEqual(layout.listClientWidth);
        }
    });

    test('downloading the Related Work CSV example does not validate or submit the editor form', async ({ page }) => {
        await gotoWithLocalTlsRetry(page, '/login');
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await expect(page).toHaveURL(/\/dashboard/, { timeout: 30_000 });

        await gotoWithLocalTlsRetry(page, '/editor');
        await expect(page.getByTestId('resource-info-section')).toBeVisible({ timeout: 30_000 });

        const relatedWorkSection = page.getByTestId('related-work-section');
        const relatedWorkTrigger = page.getByTestId('related-work-accordion-trigger');
        await expect(relatedWorkSection).toBeVisible();

        if ((await relatedWorkSection.getAttribute('data-state')) !== 'open') {
            await relatedWorkTrigger.click();
            await expect(relatedWorkSection).toHaveAttribute('data-state', 'open');
        }

        await page.getByTestId('related-work-empty-state').getByRole('button', { name: 'Import CSV' }).click();

        const csvImporter = page.getByText('CSV Bulk Import', { exact: true });
        const validationAlert = page.getByTestId('global-validation-alert');
        const invalidFields = page.locator('[aria-invalid="true"]');
        await expect(csvImporter).toBeVisible();
        await expect(validationAlert).toBeHidden();
        const invalidFieldCountBeforeDownload = await invalidFields.count();

        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('button', { name: 'Download Example' }).click();
        const download = await downloadPromise;

        expect(download.suggestedFilename()).toBe('related-works-example.csv');
        await expect(csvImporter).toBeVisible();
        await expect(validationAlert).toBeHidden();
        await expect(invalidFields).toHaveCount(invalidFieldCountBeforeDownload);
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
