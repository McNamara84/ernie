import { expect, type Locator, type Page } from '@playwright/test';

import { test } from '../fixtures/authenticated';

function calendar(page: Page) {
    return page.locator('[data-slot="popover-content"] [data-slot="calendar"]');
}

async function chooseMonthAndYear(page: Page, month: string, year: string) {
    const picker = calendar(page);
    const monthSelect = picker.getByRole('combobox', { name: 'Choose the Month' });
    const yearSelect = picker.getByRole('combobox', { name: 'Choose the Year' });

    // selectOption alone bypasses the navigation overlay that caused #1461.
    await monthSelect.click({ trial: true });
    await yearSelect.click({ trial: true });
    await monthSelect.selectOption(month);
    await yearSelect.selectOption(year);
    await expect(monthSelect).toHaveValue(month);
    await expect(yearSelect).toHaveValue(year);
    await expect(picker).toBeVisible();
}

async function dayButton(page: Page, year: number, month: number, day: number) {
    // data-day uses the browser's locale, which can differ from the Node host.
    const label = await page.evaluate(([year, month, day]) => new Date(year, month, day).toLocaleDateString(), [year, month, day]);
    return calendar(page).locator(`button[data-day="${label}"]`);
}

async function chooseDay(page: Page, input: Locator, year: number, month: number, day: number, expected: string) {
    await (await dayButton(page, year, month, day)).click();
    await expect(input).toHaveValue(expected);
    await expect(calendar(page)).toBeHidden();
}

async function chooseDateType(page: Page, name: string) {
    await page.getByRole('combobox', { name: 'Date Type', exact: true }).click();
    await page.getByRole('option', { name, exact: true }).click();
}

test.describe('Issue #1461: editor calendar dropdowns', () => {
    test.use({ timezoneId: 'Europe/Berlin' });

    test.beforeEach(async ({ page }) => {
        await page.clock.setFixedTime(new Date('2026-10-12T12:00:00Z'));
        await page.goto('/editor');
        const dates = page.locator('[data-slot="accordion-trigger"]', { hasText: 'Dates' });
        await expect(dates).toBeVisible();
        if ((await dates.getAttribute('aria-expanded')) !== 'true') await dates.click();
        await page.getByRole('button', { name: 'Add Date', exact: true }).click();
        await chooseDateType(page, 'Created');
    });

    for (const scenario of [
        { name: 'existing ISO date on desktop', locale: 'en-GB', width: 1280, initial: '2020-09-24', expected: '1900-02-02' },
        { name: 'empty German date on mobile', locale: 'de-DE', width: 375, initial: '', expected: '02.02.1900' },
    ]) {
        test.describe(scenario.name, () => {
            test.use({ locale: scenario.locale, viewport: { width: scenario.width, height: 900 } });

            test('can click both dropdowns and arrows before committing a historical day', async ({ page }) => {
                const input = page.getByRole('textbox', { name: 'Date', exact: true });
                if (scenario.initial) {
                    await input.fill(scenario.initial);
                    await input.blur();
                }
                await expect(input).toHaveValue(scenario.initial);
                const trigger = page.getByRole('button', { name: 'Choose date', exact: true });
                await trigger.click();
                await chooseMonthAndYear(page, '1', '1900');
                await expect(input).toHaveValue(scenario.initial);

                await calendar(page).getByRole('button', { name: 'Go to the Next Month' }).click();
                await expect(calendar(page).getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('2');
                await calendar(page).getByRole('button', { name: 'Go to the Previous Month' }).click();
                await expect(calendar(page).getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
                await chooseDay(page, input, 1900, 1, 2, scenario.expected);

                await trigger.click();
                await expect(calendar(page).getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
                await expect(calendar(page).getByRole('combobox', { name: 'Choose the Year' })).toHaveValue('1900');
                await page.keyboard.press('Escape');
                await expect(calendar(page)).toBeHidden();
                await expect(trigger).toBeFocused();
            });
        });
    }

    test('keeps start and end calendar selections independent in a period', async ({ page }) => {
        await page.getByRole('radio', { name: 'Period', exact: true }).click();
        const start = page.getByRole('textbox', { name: 'Start Date', exact: true });
        const end = page.getByRole('textbox', { name: 'End Date', exact: true });

        await page.getByRole('button', { name: 'Choose start date', exact: true }).click();
        await chooseMonthAndYear(page, '1', '1900');
        await chooseDay(page, start, 1900, 1, 2, '1900-02-02');
        await expect(end).toHaveValue('');

        await page.getByRole('button', { name: 'Choose end date', exact: true }).click();
        await chooseMonthAndYear(page, '1', '1900');
        await chooseDay(page, end, 1900, 1, 3, '1900-02-03');
        await expect(start).toHaveValue('1900-02-02');
    });

    test('preserves the earliest date and blocks future ordinary dates', async ({ page }) => {
        await page.getByRole('button', { name: 'Choose date', exact: true }).click();
        await chooseMonthAndYear(page, '0', '1900');
        await expect(calendar(page).getByRole('button', { name: 'Go to the Previous Month' })).toHaveAttribute('aria-disabled', 'true');
        await expect(calendar(page).getByRole('combobox', { name: 'Choose the Year' }).locator('option[value="1899"]')).toHaveCount(0);
        await expect(await dayButton(page, 1899, 11, 31)).toHaveCount(0);
        await expect(await dayButton(page, 1900, 0, 1)).toBeEnabled();

        await chooseMonthAndYear(page, '9', '2026');
        await expect(calendar(page).getByRole('button', { name: 'Go to the Next Month' })).toHaveAttribute('aria-disabled', 'true');
        await expect(calendar(page).getByRole('combobox', { name: 'Choose the Year' }).locator('option[value="2027"]')).toHaveCount(0);
        await expect(await dayButton(page, 2026, 9, 12)).toBeEnabled();
        await expect(await dayButton(page, 2026, 9, 13)).toBeDisabled();
    });

    test('can navigate to and choose a future Available date', async ({ page }) => {
        await chooseDateType(page, 'Available');
        const input = page.getByRole('textbox', { name: 'Date', exact: true });
        await page.getByRole('button', { name: 'Choose date', exact: true }).click();
        await chooseMonthAndYear(page, '0', '2028');
        await expect(input).toHaveValue('');
        await expect(calendar(page).getByRole('combobox', { name: 'Choose the Year' }).locator('option[value="2076"]')).toHaveCount(1);
        await chooseDay(page, input, 2028, 0, 15, '2028-01-15');
    });

    test('supports keyboard month selection and returns focus when dismissed', async ({ page }) => {
        const input = page.getByRole('textbox', { name: 'Date', exact: true });
        await input.fill('2020-09-24');
        await input.blur();
        const trigger = page.getByRole('button', { name: 'Choose date', exact: true });
        await trigger.focus();
        await page.keyboard.press('Enter');
        const month = calendar(page).getByRole('combobox', { name: 'Choose the Month' });
        const year = calendar(page).getByRole('combobox', { name: 'Choose the Year' });
        await month.focus();
        await month.press('ArrowDown');
        await expect(month).toHaveValue('9');
        await month.press('Tab');
        await expect(year).toBeFocused();
        await year.press('ArrowUp');
        await expect(year).toHaveValue('2019');
        await expect(input).toHaveValue('2020-09-24');
        await page.keyboard.press('Escape');
        await expect(calendar(page)).toBeHidden();
        await expect(trigger).toBeFocused();
    });
});
