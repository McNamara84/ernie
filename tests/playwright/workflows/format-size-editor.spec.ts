import { expect } from '@playwright/test';

import { test } from '../fixtures/authenticated';

test('edits resource formats and sizes before landing-page setup and preserves them after saving the modal', async ({ page }) => {
    test.setTimeout(240_000);
    await page.goto('/editor');
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    await page.getByTestId('main-title-input').fill(`Format and size ${Date.now()}`);
    const section = page.getByTestId('format-size-section');
    if (!(await section.getByTestId('format-size-fields').isVisible())) {
        await section.getByRole('button', { name: /Format & Size/ }).click();
    }
    await section.getByRole('button', { name: 'Add format' }).click();
    await page.getByTestId('format-select-0').click();
    await page.getByPlaceholder('Search all IANA MIME types...').fill('text/csv');
    await page.getByRole('option', { name: 'text/csv', exact: true }).click();
    await section.getByRole('button', { name: 'Add size' }).click();
    await page.getByTestId('size-value-0').fill('1,5');
    await expect(page.getByTestId('size-value-0')).toHaveValue('1,5');
    await page.getByTestId('size-unit-0').click();
    await page.getByRole('option', { name: 'GiB', exact: true }).click();
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await expect(page.getByTestId('size-unit-0')).toContainText('GiB');
    await expect(page.getByTestId('size-value-0')).toHaveValue('1,5');

    const save = page.waitForResponse((response) => response.url().endsWith('/editor/resources/draft') && response.request().method() === 'POST');
    await page.getByTestId('save-draft-button').click();
    const response = await save;
    expect(response.ok()).toBeTruthy();
    const saved = await response.json();
    expect(saved.contentMetadata.formats[0].value).toBe('text/csv');
    expect(saved.contentMetadata.sizes[0].numeric_value).toBe('1.5000');
    const resourceId = saved.resource.id;

    await page.goto(`/editor?resourceId=${resourceId}`);
    await expect(page.getByTestId('format-select-0')).toHaveText('text/csv', { timeout: 60_000 });
    await expect(page.getByTestId('size-value-0')).toHaveValue('1.5000');
    await expect(page.getByTestId('size-unit-0')).toContainText('GiB');
    await page.getByTestId('show-lp-preview-button').click();
    const modal = page.getByTestId('setup-lp-modal-content');
    await expect(modal).toBeVisible({ timeout: 30_000 });
    await expect(modal.getByTestId('primary-download-format')).toHaveCount(0);
    await expect(modal.getByTestId('primary-download-size')).toHaveCount(0);
    const setupSave = modal.getByRole('button', { name: 'Create Preview', exact: true });
    await setupSave.click();
    await expect(modal).toHaveCount(0, { timeout: 30_000 });
    await page.goto(`/editor?resourceId=${resourceId}`);
    await expect(page.getByTestId('format-select-0')).toHaveText('text/csv', { timeout: 60_000 });
    await expect(page.getByTestId('size-value-0')).toHaveValue('1.5000');
});
