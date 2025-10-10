import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import TemporalInputs from '@/components/curation/fields/spatial-temporal-coverage/TemporalInputs';

describe('TemporalInputs', () => {
    const mockOnChange = vi.fn();

    const defaultProps = {
        startDate: '',
        endDate: '',
        startTime: '',
        endTime: '',
        timezone: '',
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    test('renders all temporal input fields', () => {
        const { container } = render(<TemporalInputs {...defaultProps} />);

        // Date inputs don't have textbox role
        expect(container.querySelector('#start-date')).toBeInTheDocument();
        expect(container.querySelector('#end-date')).toBeInTheDocument();
        expect(container.querySelector('#start-time')).toBeInTheDocument();
        expect(container.querySelector('#end-time')).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: /timezone/i })).toBeInTheDocument();
    });

    test('displays values correctly', () => {
        render(
            <TemporalInputs
                {...defaultProps}
                startDate="2024-01-01"
                startTime="09:00"
                endDate="2024-12-31"
                endTime="17:00"
                timezone="Europe/Berlin"
            />,
        );

        expect(screen.getByDisplayValue('2024-01-01')).toBeInTheDocument();
        expect(screen.getByDisplayValue('2024-12-31')).toBeInTheDocument();
        expect(screen.getByDisplayValue('09:00')).toBeInTheDocument();
        expect(screen.getByDisplayValue('17:00')).toBeInTheDocument();
    });

    test('calls onChange when start date is changed', async () => {
        const user = userEvent.setup();
        const { container } = render(<TemporalInputs {...defaultProps} />);

        const startDateInput = container.querySelector('#start-date') as HTMLInputElement;

        await user.type(startDateInput, '2024-06-15');

        expect(mockOnChange).toHaveBeenCalledWith('startDate', '2024-06-15');
    });

    test('marks start and end dates as required', () => {
        const { container } = render(<TemporalInputs {...defaultProps} />);

        const startDateInput = container.querySelector('#start-date') as HTMLInputElement;
        const endDateInput = container.querySelector('#end-date') as HTMLInputElement;

        expect(startDateInput).toBeRequired();
        expect(endDateInput).toBeRequired();
    });

    test('renders timezone selector with options', () => {
        render(<TemporalInputs {...defaultProps} />);

        const timezoneSelect = screen.getByRole('combobox', { name: /timezone/i });
        expect(timezoneSelect).toBeInTheDocument();
        expect(timezoneSelect).toHaveAttribute('aria-required', 'true');
    });

    test('shows validation error when start date is after end date', () => {
        render(
            <TemporalInputs
                {...defaultProps}
                startDate="2024-12-31"
                endDate="2024-01-01"
            />,
        );

        expect(
            screen.getByText(/Start date must be before or equal to end date/i),
        ).toBeInTheDocument();
    });

    test('shows validation error when start time is after end time on same date', () => {
        render(
            <TemporalInputs
                {...defaultProps}
                startDate="2024-01-01"
                endDate="2024-01-01"
                startTime="17:00"
                endTime="09:00"
            />,
        );

        expect(
            screen.getByText(/Start time must be before end time when dates are the same/i),
        ).toBeInTheDocument();
    });

    test('time inputs are optional', () => {
        const { container } = render(<TemporalInputs {...defaultProps} />);

        const startTimeInput = container.querySelector('#start-time') as HTMLInputElement;
        const endTimeInput = container.querySelector('#end-time') as HTMLInputElement;

        expect(startTimeInput).not.toBeRequired();
        expect(endTimeInput).not.toBeRequired();
    });

    test('displays section labels', () => {
        render(<TemporalInputs {...defaultProps} />);

        expect(screen.getByText(/^Temporal Information$/i)).toBeInTheDocument();
        expect(screen.getByText(/^Start$/i)).toBeInTheDocument();
        expect(screen.getByText(/^End$/i)).toBeInTheDocument();
    });
});
