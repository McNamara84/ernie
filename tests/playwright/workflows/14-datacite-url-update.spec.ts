import { expect, test } from '@playwright/test';

import { TEST_USER_EMAIL, TEST_USER_PASSWORD } from '../constants';

const listCases = [
    {
        path: '/resources',
        scope: 'resources',
        scopeLabel: 'Resources',
        buttonTestId: 'resources-datacite-url-update',
        identifier: '10.5880/example-resource',
    },
    {
        path: '/igsns',
        scope: 'igsns',
        scopeLabel: 'IGSNs',
        buttonTestId: 'igsns-datacite-url-update',
        identifier: '10.60510/example-igsn',
    },
] as const;

test.describe('DataCite landing-page URL update preview', () => {
    test.beforeEach(async ({ page }) => {
        await page.goto('/login', { waitUntil: 'domcontentloaded', timeout: 60_000 });
        await page.getByLabel('Email address').fill(TEST_USER_EMAIL);
        await page.getByLabel('Password').fill(TEST_USER_PASSWORD);
        await page.getByRole('button', { name: 'Log in' }).click();
        await page.waitForURL(/\/dashboard/, { waitUntil: 'domcontentloaded', timeout: 30_000 });
    });

    for (const listCase of listCases) {
        test(`admin can review the before/after comparison on ${listCase.path}`, async ({ page }) => {
            const beforeUrl = `https://previous-ernie.example/${listCase.identifier}/landing`;
            const targetUrl = `https://new-ernie.example/${listCase.identifier}/landing`;

            await page.route('**/datacite/landing-page-url-updates/preview?scope=*', async (route) => {
                const requestUrl = new URL(route.request().url());
                expect(requestUrl.searchParams.get('scope')).toBe(listCase.scope);

                await route.fulfill({
                    status: 200,
                    contentType: 'application/json',
                    body: JSON.stringify({
                        scope: listCase.scope,
                        scope_label: listCase.scopeLabel,
                        total: 1,
                        sample_count: 1,
                        target_base_url: 'https://new-ernie.example',
                        test_mode: true,
                        datacite_endpoint: 'https://api.test.datacite.org',
                        can_start: true,
                        blocking_message: null,
                        items: [
                            {
                                resource_id: 42,
                                identifier: listCase.identifier,
                                before_url: beforeUrl,
                                target_url: targetUrl,
                                datacite_state: 'findable',
                                target_reachable: true,
                                outcome: 'ready',
                                message: null,
                            },
                        ],
                    }),
                });
            });

            await page.goto(listCase.path, { waitUntil: 'domcontentloaded', timeout: 60_000 });
            await page.getByTestId(listCase.buttonTestId).click();

            await expect(page.getByRole('heading', { name: new RegExp(`Update DataCite landing-page URLs.*${listCase.scopeLabel}`) })).toBeVisible();
            await expect(page.getByRole('cell', { name: listCase.identifier, exact: true })).toBeVisible();
            await expect(page.getByText(beforeUrl, { exact: true })).toBeVisible();
            await expect(page.getByText(targetUrl, { exact: true })).toBeVisible();
            await expect(page.getByText(/External landing pages are always excluded/)).toBeVisible();
            await expect(page.getByTestId('datacite-url-update-confirm')).toBeEnabled();
        });
    }
});
