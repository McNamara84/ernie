import { expect } from '@playwright/test';

import { test } from '../fixtures/authenticated';

test.describe('Combined PIDINST refresh', () => {
    for (const outcome of ['completed', 'failed'] as const) {
        test(`shows source counts and a ${outcome} update without fetching external registries`, async ({ page }) => {
            await page.route('**/pid-settings/pid4inst/check', (route) =>
                route.fulfill({
                    json: {
                        localCount: 12,
                        remoteCount: 15,
                        updateAvailable: true,
                        comparisonBasis: 'count',
                        sources: { b2inst: { syncedCount: 9, remoteCount: 10 }, datacite: { syncedCount: 6, remoteCount: 5 } },
                    },
                }),
            );
            await page.route('**/pid-settings/pid4inst/update', (route) => route.fulfill({ json: { jobId: 'pidinst-browser-test' } }));
            await page.route('**/pid-settings/update-status/pidinst-browser-test', (route) =>
                route.fulfill({
                    json:
                        outcome === 'completed'
                            ? {
                                  status: outcome,
                                  pidType: 'pid4inst',
                                  progress: 'Update completed successfully',
                                  itemCount: 11,
                                  lastUpdated: '2026-11-17T10:00:00Z',
                              }
                            : {
                                  status: outcome,
                                  pidType: 'pid4inst',
                                  progress: 'Update failed',
                                  error: 'DataCite page incomplete; previous list retained.',
                              },
                }),
            );
            await page.goto('/settings');
            const section = page.getByTestId('settings-accordion').locator('[data-accordion-value="persistent-identifiers"]');
            await section.locator('[data-slot="accordion-trigger"]').click();
            const row = page.getByTestId('pid-setting-row-pid4inst');
            await row.getByRole('button', { name: 'Check for Updates' }).click();
            await expect(row.getByText(/B2INST: 10 records; DataCite: 5 records/)).toBeVisible();
            await expect(row.getByText(/currently offers 12 instruments/)).toBeVisible();
            await row.getByRole('button', { name: 'Update Now' }).click();
            if (outcome === 'completed') {
                await expect(row.getByText('11 instruments', { exact: true })).toBeVisible();
            } else {
                await expect(row.getByText(/DataCite page incomplete/)).toBeVisible();
                await expect(row.getByRole('button', { name: 'Update Now' })).toBeEnabled();
            }
        });
    }
});
