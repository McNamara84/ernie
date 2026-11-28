import { expect, test } from '@playwright/test';

import { curationFixture as fixture } from '../helpers/curation-fixture';
import { loginAsTestUser } from '../helpers/test-helpers';

test.describe('Curation tracking', () => {
    test('hands over a resource through My Resources and preserves unsaved metadata', async ({ page }, testInfo) => {
        test.setTimeout(180_000);
        const data = fixture(testInfo, 'create');
        try {
            await loginAsTestUser(page, data.email, data.password);
            await page.goto('/curation?mine=1');
            await expect(page.getByRole('checkbox', { name: 'My Resources' })).toBeChecked();
            await page.getByRole('button', { name: 'Show details', exact: true }).click();
            await expect(page.getByRole('heading', { name: 'Open checklist items', exact: true })).toBeVisible();
            await page.getByRole('combobox', { name: 'Sort by' }).click();
            await page.getByRole('option', { name: 'First saved', exact: true }).click();
            await expect(page).toHaveURL(/sort=created/);
            await expect(page.getByRole('button', { name: 'Hide details', exact: true })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Open checklist items', exact: true })).toBeVisible();
            await page.getByRole('link', { name: data.title, exact: true }).click();
            await expect(page).toHaveURL(new RegExp(`resourceId=${data.id}`));
            await page.route('**/editor/resources/draft', (route) => route.abort());
            const titleInput = page.getByTestId('main-title-input');
            await expect(titleInput).toHaveValue(data.title);
            await titleInput.fill(`${data.title} unsaved`);
            await expect(titleInput).toHaveValue(`${data.title} unsaved`);
            const trigger = page.getByRole('button', { name: 'Curation', exact: true }).filter({ visible: true });
            await trigger.click();
            const panel = page.getByRole('dialog', { name: 'Curation', exact: true });
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeVisible();
            const saved = page.waitForResponse(
                (response) => response.url().endsWith('/curation/checklist/data_received') && response.request().method() === 'PATCH',
            );
            await panel.getByRole('checkbox', { name: 'Data received', exact: true }).click();
            expect((await saved).status()).toBe(200);
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeChecked();
            await panel.getByRole('button', { name: 'Hand over', exact: true }).click();
            await panel.getByRole('combobox', { name: 'New lead curator' }).click();
            await page.getByRole('option', { name: `Browser Handover (next-${data.tag}@example.test)`, exact: true }).click();
            await panel.getByLabel('Handover note (optional)').fill('Check the time zone before review.');
            await panel.getByRole('button', { name: 'Save responsibility' }).click();
            await expect(panel.getByText('Lead: Browser Handover', { exact: true })).toBeVisible();
            await expect(panel.getByText('Browser Curator', { exact: true }).first()).toBeVisible();
            await panel.getByLabel('New note', { exact: true }).fill('Data is ready for review.');
            await panel.getByRole('button', { name: 'Add note', exact: true }).click();
            await expect(panel.getByText('Data is ready for review.', { exact: true })).toBeVisible();
            await page.keyboard.press('Escape');
            await expect(titleInput).toHaveValue(`${data.title} unsaved`);
            await titleInput.fill(data.title);
            await page.unroute('**/editor/resources/draft');
            await page.goto('/curation?mine=1');
            await expect(page.getByRole('link', { name: data.title, exact: true })).toBeVisible();
            await page.getByRole('button', { name: 'Show details' }).click();
            await expect(page.getByText('Data is ready for review.', { exact: true })).toBeVisible();
        } finally {
            fixture(testInfo, 'clean', data);
        }
    });

    test('offers keyboard-accessible read-only curation to beginners on mobile', async ({ page }, testInfo) => {
        test.setTimeout(180_000);
        const data = fixture(testInfo, 'create');
        try {
            await page.setViewportSize({ width: 375, height: 812 });
            await loginAsTestUser(page, data.beginnerEmail, data.password);
            await page.goto(`/editor?resourceId=${data.id}`);
            const trigger = page.getByRole('button', { name: 'Curation', exact: true }).filter({ visible: true });
            await trigger.focus();
            await page.keyboard.press('Enter');
            const panel = page.getByRole('dialog', { name: 'Curation', exact: true });
            await expect(panel.getByText('Read-only access to curation.')).toBeVisible();
            await expect(panel.getByRole('checkbox', { name: 'Data received', exact: true })).toBeDisabled();
            await expect(panel.getByRole('button', { name: 'Add note', exact: true })).toHaveCount(0);
            await expect(panel).toBeInViewport();
            await page.keyboard.press('Escape');
            await expect(trigger).toBeFocused();
        } finally {
            fixture(testInfo, 'clean', data);
        }
    });
});
