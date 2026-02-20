import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

import { DateRangePicker } from '@/components/ui/date-range-picker';

describe('DateRangePicker', () => {
    it('renders with placeholder', () => {
        render(<DateRangePicker />);
        expect(screen.getByText('Pick a date range')).toBeInTheDocument();
    });

    it('renders custom placeholder', () => {
        render(<DateRangePicker placeholder="Select dates" />);
        expect(screen.getByText('Select dates')).toBeInTheDocument();
    });

    it('renders disabled state', () => {
        render(<DateRangePicker disabled />);
        expect(screen.getByRole('combobox')).toBeDisabled();
    });

    it('renders error state', () => {
        render(<DateRangePicker error />);
        expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
    });

    it('renders required state', () => {
        render(<DateRangePicker required />);
        expect(screen.getByRole('combobox')).toHaveAttribute('aria-required', 'true');
    });

    it('shows selected date range', () => {
        render(
            <DateRangePicker
                value={{ from: new Date(2024, 0, 15), to: new Date(2024, 0, 20) }}
            />,
        );

        expect(screen.getByText(/Jan 15, 2024/)).toBeInTheDocument();
        expect(screen.getByText(/Jan 20, 2024/)).toBeInTheDocument();
    });

    it('shows only from date when to is not set', () => {
        render(
            <DateRangePicker value={{ from: new Date(2024, 0, 15) }} />,
        );

        expect(screen.getByText('Jan 15, 2024')).toBeInTheDocument();
    });

    it('shows clear button when value is set', () => {
        render(
            <DateRangePicker
                value={{ from: new Date(2024, 0, 15), to: new Date(2024, 0, 20) }}
                clearable
            />,
        );

        expect(screen.getByLabelText('Clear date range')).toBeInTheDocument();
    });

    it('does not show clear button when not clearable', () => {
        render(
            <DateRangePicker
                value={{ from: new Date(2024, 0, 15), to: new Date(2024, 0, 20) }}
                clearable={false}
            />,
        );

        expect(screen.queryByLabelText('Clear date range')).not.toBeInTheDocument();
    });

    it('calls onChange with undefined when clearing', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();

        render(
            <DateRangePicker
                value={{ from: new Date(2024, 0, 15), to: new Date(2024, 0, 20) }}
                onChange={onChange}
                clearable
            />,
        );

        await user.click(screen.getByLabelText('Clear date range'));
        expect(onChange).toHaveBeenCalledWith(undefined);
    });

    it('renders hidden inputs when names are provided', () => {
        const { container } = render(
            <DateRangePicker
                value={{ from: new Date(2024, 0, 15), to: new Date(2024, 0, 20) }}
                names={['start_date', 'end_date']}
            />,
        );

        const startInput = container.querySelector('input[name="start_date"]');
        const endInput = container.querySelector('input[name="end_date"]');
        expect(startInput).toBeInTheDocument();
        expect(endInput).toBeInTheDocument();
        expect(startInput).toHaveValue('2024-01-15');
        expect(endInput).toHaveValue('2024-01-20');
    });

    it('renders empty hidden inputs when no value', () => {
        const { container } = render(
            <DateRangePicker names={['start_date', 'end_date']} />,
        );

        const startInput = container.querySelector('input[name="start_date"]');
        expect(startInput).toHaveValue('');
    });

    it('opens calendar on click', async () => {
        const user = userEvent.setup();
        render(<DateRangePicker numberOfMonths={1} />);

        await user.click(screen.getByRole('combobox'));

        // The calendar renders a grid for the month
        await expect(screen.findByRole('grid')).resolves.toBeInTheDocument();
    });
});
