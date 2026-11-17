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
    await page.getByPlaceholder('Search formats or MIME types...').fill('text/csv');
    await page.getByRole('option', { name: 'CSV (text/csv)', exact: true }).click();
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
    await expect(page.getByTestId('format-select-0')).toHaveText('CSV', { timeout: 60_000 });
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
    await expect(page.getByTestId('format-select-0')).toHaveText('CSV', { timeout: 60_000 });
    await expect(page.getByTestId('size-value-0')).toHaveValue('1.5000');
});

test('keeps format and size controls usable at desktop, tablet, and mobile widths', async ({ page }, testInfo) => {
    test.setTimeout(240_000);
    await page.goto('/editor');
    await expect(page.getByTestId('main-title-input')).toBeVisible({ timeout: 60_000 });
    await page.getByTestId('main-title-input').fill('Resource formats and sizes');
    const collapse = page.getByTestId('resource-info-section').getByRole('button', { name: 'Collapse all field groups', exact: true });
    if (await collapse.isVisible()) await collapse.click();
    const section = page.getByTestId('format-size-section');
    const trigger = section.getByRole('button', { name: /Format & Size/ });
    if ((await trigger.getAttribute('aria-expanded')) !== 'true') await trigger.click();

    for (const [index, mimeType] of ['text/csv', 'application/json'].entries()) {
        await section.getByRole('button', { name: 'Add format', exact: true }).click();
        await page.getByTestId(`format-select-${index}`).click();
        await page.getByPlaceholder('Search formats or MIME types...').fill(mimeType);
        await page.getByRole('option', { name: `${mimeType === 'text/csv' ? 'CSV' : 'JSON'} (${mimeType})`, exact: true }).click();
    }
    for (const [index, size] of [
        { value: '1,5', unit: 'GiB' },
        { value: '2048', unit: 'KiB' },
    ].entries()) {
        await section.getByRole('button', { name: 'Add size', exact: true }).click();
        await page.getByTestId(`size-value-${index}`).fill(size.value);
        await page.getByTestId(`size-unit-${index}`).click();
        await page.getByRole('option', { name: size.unit, exact: true }).click();
    }

    for (const viewport of [
        { name: 'desktop', width: 1440, height: 1100 },
        { name: 'tablet', width: 768, height: 1024 },
        { name: 'mobile', width: 390, height: 844 },
    ]) {
        await page.setViewportSize({ width: viewport.width, height: viewport.height });
        await page.mouse.move(0, 0);
        await section.scrollIntoViewIfNeeded();
        await expect(page.getByTestId('format-select-0')).toHaveText('CSV');
        await expect(page.getByTestId('size-value-0')).toHaveValue('1,5');
        await expect(page.getByTestId('size-unit-0')).toContainText('GiB');
        const wasLarge = await page.evaluate(() => document.documentElement.classList.contains('font-large'));
        for (const large of [false, true]) {
            // Exercise both supported font scales locally without changing the user's saved preference.
            await page.evaluate((enabled) => document.documentElement.classList.toggle('font-large', enabled), large);
            for (const index of [0, 1]) {
                await expect
                    .poll(() =>
                        page.evaluate((row) => {
                            const number = document.querySelector(`[data-testid="size-value-${row}"]`)!.getBoundingClientRect();
                            const unit = document.querySelector(`[data-testid="size-unit-${row}"]`)!.getBoundingClientRect();
                            if (number.height === 0 || unit.height === 0) return Infinity;
                            return Math.max(Math.abs(number.top - unit.top), Math.abs(number.height - unit.height));
                        }, index),
                    )
                    .toBeLessThanOrEqual(1);
            }
        }
        await page.evaluate((enabled) => document.documentElement.classList.toggle('font-large', enabled), wasLarge);
        expect(await section.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBeTruthy();

        if (testInfo.project.name === 'chromium') {
            // Capture the complete section without the unrelated floating toolbar
            // covering it. Interactions below still use the original viewport.
            const sectionHeight = (await section.boundingBox())!.height;
            const toolbarHeight = (await page.getByTestId('editor-floating-actions').boundingBox())!.height;
            await page.setViewportSize({ width: viewport.width, height: Math.max(viewport.height, Math.ceil(sectionHeight + toolbarHeight + 192)) });
            await section.evaluate((element) => element.scrollIntoView({ block: 'start' }));
            await page.evaluate(() => window.scrollBy(0, -96));
            const screenshot = testInfo.outputPath(`format-size-${viewport.name}.png`);
            await section.screenshot({ path: screenshot, animations: 'disabled' });
            await testInfo.attach(`Format & Size ${viewport.width}px`, { path: screenshot, contentType: 'image/png' });
            await page.setViewportSize({ width: viewport.width, height: viewport.height });
        }

        await page.getByTestId('format-select-0').click();
        await page.getByPlaceholder('Search formats or MIME types...').fill('application/');
        await expect(page.getByRole('option').first()).toBeVisible();
        if (testInfo.project.name === 'chromium' && viewport.name !== 'tablet') {
            const screenshot = testInfo.outputPath(`mime-search-${viewport.name}.png`);
            await page.screenshot({ path: screenshot, animations: 'disabled' });
            await testInfo.attach(`MIME search ${viewport.width}px`, { path: screenshot, contentType: 'image/png' });
        }
        await page.keyboard.press('Escape');
        await page.getByTestId('size-unit-0').evaluate((element) => element.scrollIntoView({ block: 'center' }));
        await page.getByTestId('size-unit-0').click();
        await expect(page.getByRole('option', { name: 'GiB', exact: true })).toBeVisible();
        if (testInfo.project.name === 'chromium' && viewport.name === 'tablet') {
            const screenshot = testInfo.outputPath('size-units-tablet.png');
            await page.screenshot({ path: screenshot, animations: 'disabled' });
            await testInfo.attach('Size units 768px', { path: screenshot, contentType: 'image/png' });
        }
        await page.keyboard.press('Escape');
    }
});
