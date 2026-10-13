import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { EditorDateInput } from '@/components/curation/fields/editor-date-input';
import type { EditorDateLocale } from '@/lib/editor-date';

function DateInputHarness({
    initial,
    locale = 'iso',
    allowFuture = false,
    onValueChange = vi.fn(),
}: {
    initial: string;
    locale?: EditorDateLocale;
    allowFuture?: boolean;
    onValueChange?: (value: string, committed?: boolean) => void;
}) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <label htmlFor="date-input">Date</label>
            <EditorDateInput
                id="date-input"
                inputLabel="Date"
                value={value}
                locale={locale}
                allowFuture={allowFuture}
                calendarLabel="Choose date"
                clearLabel="Clear date"
                onChange={(next, committed) => {
                    setValue(next);
                    onValueChange(next, committed);
                }}
            />
        </>
    );
}

describe('EditorDateInput', () => {
    it('accepts German input and commits an ISO date on blur', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2020-09-24" locale="de" onValueChange={onValueChange} />);
        const input = screen.getByRole('textbox', { name: 'Date' });

        expect(input).toHaveValue('24.09.2020');
        await user.clear(input);
        await user.type(input, '25.09.2020');
        await user.tab();

        expect(onValueChange).toHaveBeenLastCalledWith('2020-09-25', true);
        expect(input).toHaveValue('25.09.2020');
    });

    it('commits a typed date with Enter', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2020-09-24" locale="de" onValueChange={onValueChange} />);
        const input = screen.getByRole('textbox', { name: 'Date' });

        await user.clear(input);
        await user.type(input, '25.09.2020{Enter}');

        expect(onValueChange).toHaveBeenLastCalledWith('2020-09-25', true);
        expect(input).toHaveValue('25.09.2020');
    });

    it('retains invalid typed text with an accessible error', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="2020-09-24" locale="de" />);
        const input = screen.getByRole('textbox', { name: 'Date' });

        await user.clear(input);
        await user.type(input, '31.02.2020');
        await user.tab();

        expect(input).toHaveValue('31.02.2020');
        expect(input).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid date');
    });

    it('keeps a year-month value editable and clears it', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="2020-06" />);
        const input = screen.getByRole('textbox', { name: 'Date' });

        expect(input).toHaveValue('2020-06');
        await user.clear(input);
        await user.type(input, '2020');
        await user.tab();
        expect(input).toHaveValue('2020');
        await user.click(screen.getByRole('button', { name: 'Clear date' }));
        expect(input).toHaveValue('');
    });

    it('selects a historical calendar day and commits its ISO value', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="1900-02-01" onValueChange={onValueChange} />);

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const dayLabel = new Date(1900, 1, 2).toLocaleDateString();
        const dayButton = document.querySelector<HTMLButtonElement>(`button[data-day="${dayLabel}"]`);
        expect(dayButton).toBeTruthy();
        await user.click(dayButton!);

        expect(onValueChange).toHaveBeenLastCalledWith('1900-02-02', true);
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('1900-02-02');
    });

    it('allows choosing a future day when the date type permits it', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        const futureYear = new Date().getFullYear() + 2;
        const initial = `${futureYear}-10-12`;
        render(<DateInputHarness initial={initial} allowFuture onValueChange={onValueChange} />);

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const dayLabel = new Date(futureYear, 9, 13).toLocaleDateString();
        const dayButton = document.querySelector<HTMLButtonElement>(`button[data-day="${dayLabel}"]`);
        expect(dayButton).toBeTruthy();
        expect(dayButton).toBeEnabled();
        await user.click(dayButton!);

        expect(onValueChange).toHaveBeenLastCalledWith(`${futureYear}-10-13`, true);
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue(`${futureYear}-10-13`);
    });

    it('opens the old date in a month and year dropdown that reaches 1900', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="1900-02-01" />);

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const yearSelect = screen.getAllByRole('combobox').find((element) => element.querySelector('option[value="1900"]'));
        expect(yearSelect).toBeTruthy();
        expect(yearSelect).toHaveValue('1900');
    });

    it.each([
        { initial: '', locale: 'iso', expected: '1900-02-02' },
        { initial: '2020-09-24', locale: 'iso', expected: '1900-02-02' },
        { initial: '2020-09', locale: 'iso', expected: '1900-02-02' },
        { initial: '2020-09-24', locale: 'de', expected: '02.02.1900' },
    ] as const)('navigates without committing $locale input "$initial" until a day is selected', async ({ initial, locale, expected }) => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial={initial} locale={locale} onValueChange={onValueChange} />);
        const input = screen.getByRole('textbox', { name: 'Date' });
        const originalValue = (input as HTMLInputElement).value;

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        await user.selectOptions(screen.getByRole('combobox', { name: 'Choose the Month' }), '1');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Choose the Year' }), '1900');
        await user.click(screen.getByRole('button', { name: 'Go to the Next Month' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('2');
        await user.click(screen.getByRole('button', { name: 'Go to the Previous Month' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
        expect(screen.getByRole('combobox', { name: 'Choose the Year' })).toHaveValue('1900');
        expect(input).toHaveValue(originalValue);
        expect(onValueChange).not.toHaveBeenCalled();

        const dayLabel = new Date(1900, 1, 2).toLocaleDateString();
        await user.click(document.querySelector<HTMLButtonElement>(`button[data-day="${dayLabel}"]`)!);
        expect(onValueChange).toHaveBeenCalledExactlyOnceWith('1900-02-02', true);
        expect(input).toHaveValue(expected);
        expect(screen.queryByRole('grid')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
        expect(screen.getByRole('combobox', { name: 'Choose the Year' })).toHaveValue('1900');
    });
});
