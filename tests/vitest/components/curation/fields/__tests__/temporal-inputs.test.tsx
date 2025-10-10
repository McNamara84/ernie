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
        render(<TemporalInputs {...defaultProps} />);

        // Check for start and end date inputs using specific selectors
        expect(screen.getByLabelText(/^Date \*$/i, { selector: '#start-date' })).toBeInTheDocument();
        expect(screen.getByLabelText(/^Date \*$/i, { selector: '#end-date' })).toBeInTheDocument();
        expect(screen.getByLabelText(/^Timezone \*$/i)).toBeInTheDocument();
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
        render(<TemporalInputs {...defaultProps} />);

        const startDateInput = screen.getByLabelText(/^Date \*$/i, {
            selector: '#start-date',
        });

        await user.type(startDateInput, '2024-06-15');

        expect(mockOnChange).toHaveBeenCalledWith('startDate', '2024-06-15');
    });

    test('marks start and end dates as required', () => {
        render(<TemporalInputs {...defaultProps} />);

        const startDateInput = screen.getByLabelText(/^Date \*$/i, {
            selector: '#start-date',
        });
        const endDateInput = screen.getByLabelText(/^Date \*$/i, {
            selector: '#end-date',
        });

        expect(startDateInput).toBeRequired();
        expect(endDateInput).toBeRequired();
    });

    test('renders timezone selector with options', async () => {
        const user = userEvent.setup();
        render(<TemporalInputs {...defaultProps} />);

        const timezoneSelect = screen.getByLabelText(/^Timezone \*$/i);
        expect(timezoneSelect).toBeInTheDocument();

        // Click to open the select
        await user.click(timezoneSelect);

        // Check for some common timezone options
        expect(screen.getByRole('option', { name: /UTC/i })).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /Europe\/Berlin/i })).toBeInTheDocument();
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
            screen.getByText(/Start date cannot be after end date/i),
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
            screen.getByText(/Start time cannot be after end time on the same date/i),
        ).toBeInTheDocument();
    });

    test('time inputs are optional', () => {
        render(<TemporalInputs {...defaultProps} />);

        const startTimeInput = screen.getByLabelText(/^Time \(optional\)$/i, {
            selector: '#start-time',
        });
        const endTimeInput = screen.getByLabelText(/^Time \(optional\)$/i, {
            selector: '#end-time',
        });

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
