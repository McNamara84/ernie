import { expect, type Page } from '@playwright/test';

import { test } from '../fixtures/authenticated';

type EditorDate = { dateType: string; dateMode: 'single' | 'range'; startDate: string; endDate: string };

const reportedDates: EditorDate[] = [
    { dateType: 'other', dateMode: 'range', startDate: '2006', endDate: '2099' },
    { dateType: 'collected', dateMode: 'range', startDate: '2100', endDate: '2299' },
    { dateType: 'collected', dateMode: 'range', startDate: '1861', endDate: '2005' },
    { dateType: 'collected', dateMode: 'range', startDate: '1661', endDate: '1860' },
    { dateType: 'created', dateMode: 'single', startDate: '2017', endDate: '' },
];

async function openDates(page: Page) {
    const trigger = page.locator('[data-slot="accordion-trigger"]').filter({ hasText: 'Important dates for your dataset.' });
    await expect(trigger).toBeVisible({ timeout: 45000 });
    if ((await trigger.getAttribute('aria-expanded')) === 'false') await trigger.click();
    return trigger.locator('xpath=ancestor::*[@data-slot="accordion-item"][1]');
}

async function prefillDates(page: Page, dates: EditorDate[]) {
    const query = new URLSearchParams({
        'titles[0][title]': 'Historical observations and future climate projections',
        'titles[0][titleType]': 'main-title',
    });
    dates.forEach((date, index) => Object.entries(date).forEach(([key, value]) => query.set('dates[' + index + '][' + key + ']', value)));
    await page.goto('/editor?' + query.toString());
    return openDates(page);
}

async function saveDraft(page: Page) {
    const response = page.waitForResponse(
        (response) => response.request().method() === 'POST' && new URL(response.url()).pathname === '/editor/resources/draft',
    );
    await page.getByTestId('save-draft-button').click();
    const saved = await response;
    expect(saved.ok()).toBe(true);
    const body = await saved.json();
    await expect(page.getByTestId('save-draft-button')).toBeEnabled();
    return { payload: saved.request().postDataJSON(), id: body.resource.id as number };
}

test.use({ viewport: { width: 1129, height: 946 }, locale: 'de-DE', timezoneId: 'Europe/Berlin' });

test('saves and reopens the five reported date rows without validation errors or precision loss', async ({ page }, testInfo) => {
    const section = await prefillDates(page, reportedDates);
    const { payload, id } = await saveDraft(page);
    expect(payload.dates).toEqual(reportedDates.map((date) => ({ ...date, endDate: date.endDate || null })));
    expect(payload.accessLevel).toBe('open');
    await expect(section.getByText('Date validation issues', { exact: true })).toHaveCount(0);
    await page.goto('/editor?resourceId=' + id);
    const reopened = await openDates(page);
    // Saved rows are ordered by date type, so compare their values independently of their position.
    await expect
        .poll(() =>
            reopened.locator('fieldset').evaluateAll((rows) =>
                rows.flatMap((row) => {
                    const start = row.querySelector<HTMLInputElement>('input[aria-label="Start Date"], input[aria-label="Date"]');
                    const type = row.querySelector('[role="combobox"]');
                    if (!start || !type) return [];
                    return [
                        {
                            dateType: type.textContent?.trim().toLowerCase(),
                            dateMode: start.getAttribute('aria-label') === 'Start Date' ? 'range' : 'single',
                            startDate: start.value,
                            endDate: row.querySelector<HTMLInputElement>('input[aria-label="End Date"]')?.value ?? '',
                        },
                    ];
                }),
            ),
        )
        .toEqual(expect.arrayContaining(reportedDates));
    await testInfo.attach('historical-and-projected-dates', { body: await reopened.screenshot(), contentType: 'image/png' });
});

test('revalidates a future period when changing its type and preserves open access for Valid', async ({ page }) => {
    const section = await prefillDates(page, [{ dateType: 'collected', dateMode: 'range', startDate: '2100', endDate: '2299' }]);
    const type = section.getByRole('combobox', { name: 'Date Type', exact: true });
    await type.click();
    await page.getByRole('option', { name: 'Created', exact: true }).click();
    await page.getByTestId('save-draft-button').click();
    const alert = section.getByRole('alert').filter({ hasText: 'Date validation issues' });
    await expect(alert.getByRole('listitem')).toHaveCount(2);
    await expect(section.getByRole('textbox', { name: 'Start Date', exact: true })).toHaveValue('2100');
    await type.click();
    await page.getByRole('option', { name: 'Valid', exact: true }).click();
    await expect(alert).toHaveCount(0);
    const { payload } = await saveDraft(page);
    expect(payload.accessLevel).toBe('open');
    expect(payload.dates).toEqual([{ dateType: 'valid', dateMode: 'range', startDate: '2100', endDate: '2299' }]);
});

test('opens early and far-future calendar years and commits only a selected day', async ({ page }) => {
    const section = await prefillDates(page, [{ dateType: 'collected', dateMode: 'range', startDate: '0001-01-01', endDate: '9999-12-31' }]);
    const start = section.getByRole('textbox', { name: 'Start Date', exact: true });
    const end = section.getByRole('textbox', { name: 'End Date', exact: true });
    await section.getByRole('button', { name: 'Choose start date', exact: true }).click();
    const picker = page.locator('[data-slot="popover-content"][data-state="open"]');
    const year = picker.getByRole('spinbutton', { name: 'Year', exact: true });
    await expect(year).toHaveValue('1');
    await expect(picker.getByRole('button', { name: 'Go to the Previous Month' })).toHaveAttribute('aria-disabled', 'true');
    await year.fill('1661');
    await year.press('Enter');
    await expect(start).toHaveValue('01.01.0001');
    const label = await page.evaluate(() => new Date(1661, 0, 2).toLocaleDateString());
    await picker.locator('button[data-day="' + label + '"]').click();
    await expect(start).toHaveValue('02.01.1661');
    await expect(page.locator('[data-slot="popover-content"] [data-slot="calendar"]')).toBeHidden();
    await section.getByRole('button', { name: 'Choose end date', exact: true }).click();
    await expect(year).toHaveValue('9999');
    await expect(picker.getByRole('button', { name: 'Go to the Next Month' })).toHaveAttribute('aria-disabled', 'true');
    await expect(picker.getByRole('option')).toHaveCount(12);
    await page.keyboard.press('Escape');
    await expect(end).toHaveValue('31.12.9999');
    const { payload } = await saveDraft(page);
    expect(payload.dates).toEqual([{ dateType: 'collected', dateMode: 'range', startDate: '1661-01-02', endDate: '9999-12-31' }]);
});
