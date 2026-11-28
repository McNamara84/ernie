import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { EditorDateInput } from '@/components/curation/fields/editor-date-input';
import { createEditorCalendarDate, type EditorDateLocale } from '@/lib/editor-date';

function DateInputHarness({
    initial,
    locale = 'iso',
    allowFuture = false,
    readOnly = false,
    onValueChange = vi.fn(),
}: {
    initial: string;
    locale?: EditorDateLocale;
    allowFuture?: boolean;
    readOnly?: boolean;
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
                readOnly={readOnly}
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
    it('commits an empty value when clearing the date with the keyboard', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="0001-01-01" onValueChange={onValueChange} />);
        const input = screen.getByRole('textbox', { name: 'Date' });
        await user.clear(input);
        await user.tab();
        expect(onValueChange).toHaveBeenLastCalledWith('', true);
        expect(input).toHaveValue('');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('preserves a read-only value when it loses focus', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2017" readOnly onValueChange={onValueChange} />);
        await user.click(screen.getByRole('textbox', { name: 'Date' }));
        await user.tab();
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('2017');
        expect(onValueChange).not.toHaveBeenCalled();
    });

    it('clears the date when deselecting the selected calendar day', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="1661-02-02" onValueChange={onValueChange} />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const label = createEditorCalendarDate(1661, 1, 2).toLocaleDateString();
        await user.click(document.querySelector<HTMLButtonElement>('button[data-day="' + label + '"]')!);
        expect(onValueChange).toHaveBeenCalledExactlyOnceWith('', true);
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('');
    });

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

    it('opens historical years with a direct year input and at most twelve month options', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="1900-02-01" />);

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(1900);
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
        expect(screen.getAllByRole('option')).toHaveLength(12);
    });

    it('preserves dropdown focus across month and year changes and restores the trigger on dismissal', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="2020-09-24" />);
        const trigger = screen.getByRole('button', { name: 'Choose date' });

        await user.click(trigger);
        await user.selectOptions(screen.getByRole('combobox', { name: 'Choose the Month' }), '9');
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveFocus();
        const yearInput = screen.getByRole('spinbutton', { name: 'Year' });
        await user.clear(yearInput);
        await user.type(yearInput, '2019{Enter}');
        expect(yearInput).toHaveFocus();
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('2020-09-24');

        await user.keyboard('{Escape}');
        expect(screen.queryByRole('grid')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
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
        const yearInput = screen.getByRole('spinbutton', { name: 'Year' });
        await user.clear(yearInput);
        await user.type(yearInput, '1900{Enter}');
        await user.click(screen.getByRole('button', { name: 'Go to the Next Month' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('2');
        await user.click(screen.getByRole('button', { name: 'Go to the Previous Month' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(1900);
        expect(input).toHaveValue(originalValue);
        expect(onValueChange).not.toHaveBeenCalled();

        const dayLabel = new Date(1900, 1, 2).toLocaleDateString();
        await user.click(document.querySelector<HTMLButtonElement>(`button[data-day="${dayLabel}"]`)!);
        expect(onValueChange).toHaveBeenCalledExactlyOnceWith('1900-02-02', true);
        expect(input).toHaveValue(expected);
        expect(screen.queryByRole('grid')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('1');
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(1900);
    });

    it.each([
        ['0001-02-01', '0001-02-02', 1],
        ['0004-02-28', '0004-02-29', 4],
        ['0099-02-01', '0099-02-02', 99],
        ['1661-02-01', '1661-02-02', 1661],
        ['2299-02-01', '2299-02-02', 2299],
        ['9999-02-01', '9999-02-02', 9999],
    ] as const)('selects %s in its actual calendar year %s', async (initial, expected, year) => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial={initial} allowFuture onValueChange={onValueChange} />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(year);
        const day = createEditorCalendarDate(year, 1, Number(expected.slice(-2)));
        const button = document.querySelector<HTMLButtonElement>('button[data-day="' + day.toLocaleDateString() + '"]');
        expect(button).toBeEnabled();
        await user.click(button!);
        expect(onValueChange).toHaveBeenCalledExactlyOnceWith(expected, true);
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue(expected);
    });

    it.each(['', '0', '-1', '10000'])('retains the current calendar and date for invalid year input "%s"', async (draft) => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2020-09" allowFuture onValueChange={onValueChange} />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const yearInput = screen.getByRole('spinbutton', { name: 'Year' });
        await user.clear(yearInput);
        if (draft) await user.type(yearInput, draft);
        await user.keyboard('{Enter}');
        expect(yearInput).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Enter a year between 1 and 9999.');
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue('8');
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('2020-09');
        expect(onValueChange).not.toHaveBeenCalled();
        await user.clear(yearInput);
        await user.type(yearInput, '1661{Enter}');
        expect(yearInput).toHaveAttribute('aria-invalid', 'false');
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(onValueChange).not.toHaveBeenCalled();
    });

    it('commits a calendar year on blur without changing a reduced-precision date', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2020" allowFuture onValueChange={onValueChange} />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const yearInput = screen.getByRole('spinbutton', { name: 'Year' });
        await user.clear(yearInput);
        await user.type(yearInput, '2299');
        await user.tab();
        expect(yearInput).toHaveValue(2299);
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('2020');
        expect(onValueChange).not.toHaveBeenCalled();
    });

    it('limits a restricted calendar to today and clamps its month when jumping to the current year', async () => {
        const user = userEvent.setup();
        const today = new Date();
        render(<DateInputHarness initial="2020-12-24" />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const yearInput = screen.getByRole('spinbutton', { name: 'Year' });
        await user.clear(yearInput);
        await user.type(yearInput, String(today.getFullYear()) + '{Enter}');
        expect(screen.getByRole('combobox', { name: 'Choose the Month' })).toHaveValue(String(today.getMonth()));
        await user.clear(yearInput);
        await user.type(yearInput, String(today.getFullYear() + 1) + '{Enter}');
        expect(yearInput).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('2020-12-24');
    });

    it.each([
        ['0001-01-01', 'Go to the Previous Month'],
        ['9999-12-31', 'Go to the Next Month'],
    ])('prevents navigating beyond the allowed calendar at %s', async (initial, buttonName) => {
        const user = userEvent.setup();
        render(<DateInputHarness initial={initial} allowFuture />);
        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const button = screen.getByRole('button', { name: buttonName });
        expect(button).toHaveAttribute('aria-disabled', 'true');
        await user.click(button);
        expect(screen.getByRole('spinbutton', { name: 'Year' })).toHaveValue(Number(initial.slice(0, 4)));
    });
});
