import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { EditorDateInput } from '@/components/curation/fields/editor-date-input';
import type { EditorDateLocale } from '@/lib/editor-date';

function DateInputHarness({
    initial,
    locale = 'iso',
    onValueChange = vi.fn(),
}: {
    initial: string;
    locale?: EditorDateLocale;
    onValueChange?: (value: string) => void;
}) {
    const [value, setValue] = useState(initial);
    return (
        <>
            <label htmlFor="date-input">Date</label>
            <EditorDateInput
                id="date-input"
                value={value}
                locale={locale}
                calendarLabel="Choose date"
                clearLabel="Clear date"
                onChange={(next) => {
                    setValue(next);
                    onValueChange(next);
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

        expect(onValueChange).toHaveBeenLastCalledWith('2020-09-25');
        expect(input).toHaveValue('25.09.2020');
    });

    it('commits a typed date with Enter', async () => {
        const user = userEvent.setup();
        const onValueChange = vi.fn();
        render(<DateInputHarness initial="2020-09-24" locale="de" onValueChange={onValueChange} />);
        const input = screen.getByRole('textbox', { name: 'Date' });

        await user.clear(input);
        await user.type(input, '25.09.2020{Enter}');

        expect(onValueChange).toHaveBeenLastCalledWith('2020-09-25');
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

        expect(onValueChange).toHaveBeenLastCalledWith('1900-02-02');
        expect(screen.getByRole('textbox', { name: 'Date' })).toHaveValue('1900-02-02');
    });

    it('opens the old date in a month and year dropdown that reaches 1900', async () => {
        const user = userEvent.setup();
        render(<DateInputHarness initial="1900-02-01" />);

        await user.click(screen.getByRole('button', { name: 'Choose date' }));
        const yearSelect = screen.getAllByRole('combobox').find((element) => element.querySelector('option[value="1900"]'));
        expect(yearSelect).toBeTruthy();
        expect(yearSelect).toHaveValue('1900');
    });
});
