import { format } from 'date-fns';
import { describe } from 'vitest';

import { DatePicker } from '@/components/ui/date-picker';

import { expect, page, render, test, vi } from '../helpers/test';

describe('DatePicker', () => {
    describe('popover interaction', () => {
        test('opens calendar when trigger is clicked', async ({ user }) => {
            await render(<DatePicker />);
            const trigger = page.getByRole('combobox');
            await trigger.click();
            await expect.element(trigger).toHaveAttribute('aria-expanded', 'true');
            await expect.element(page.getByRole('grid')).toBeVisible();
            await user.keyboard('{Escape}');
            await expect.element(trigger).toHaveAttribute('aria-expanded', 'false');
            await expect.element(trigger).toHaveFocus();
        });

        test('navigates both directions before selecting a day in another month', async () => {
            const onChange = vi.fn();
            const today = new Date();
            const date = new Date(today.getFullYear(), today.getMonth() + 1, 16);
            await render(<DatePicker value={today} onChange={onChange} />);
            const trigger = page.getByRole('combobox');
            await trigger.click();
            await page.getByRole('button', { name: 'Go to the Next Month' }).click();
            await expect.element(page.getByRole('grid', { name: format(date, 'MMMM yyyy') })).toBeVisible();
            await page.getByRole('button', { name: 'Go to the Previous Month' }).click();
            await expect.element(page.getByRole('grid', { name: format(today, 'MMMM yyyy') })).toBeVisible();
            expect(onChange).not.toHaveBeenCalled();
            await page.getByRole('button', { name: 'Go to the Next Month' }).click();
            const day = document.querySelector<HTMLButtonElement>(`button[data-day="${date.toLocaleDateString()}"]`);
            expect(day).not.toBeNull();
            await page.elementLocator(day!).click();
            expect(onChange).toHaveBeenCalledExactlyOnceWith(date);
            await expect.element(trigger).toHaveAttribute('aria-expanded', 'false');
        });
    });
});

test('clears a selected date through its real pointer target without opening the calendar', async () => {
    const onChange = vi.fn();
    await render(<DatePicker value={new Date(2026, 0, 15)} onChange={onChange} clearable />);
    await page.getByLabelText('Clear date').click();
    expect(onChange).toHaveBeenCalledExactlyOnceWith(undefined);
    await expect.element(page.getByRole('combobox')).toHaveAttribute('aria-expanded', 'false');
});
