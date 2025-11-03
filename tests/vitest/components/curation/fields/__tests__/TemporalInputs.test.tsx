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
        timezone: 'UTC',
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    describe('Rendering', () => {
        test('renders all temporal input fields', () => {
            render(<TemporalInputs {...defaultProps} />);

            expect(screen.getByLabelText(/^Date \(optional\)$/i, { selector: '#start-date' })).toBeInTheDocument();
            expect(screen.getByLabelText(/^Time \(optional\)$/i, { selector: '#start-time' })).toBeInTheDocument();
            expect(screen.getByLabelText(/^Date \(optional\)$/i, { selector: '#end-date' })).toBeInTheDocument();
            expect(screen.getByLabelText(/^Time \(optional\)$/i, { selector: '#end-time' })).toBeInTheDocument();
            expect(screen.getByLabelText(/^Timezone \(optional\)$/i)).toBeInTheDocument();
        });

        test('renders with labels when showLabels is true', () => {
            render(<TemporalInputs {...defaultProps} showLabels={true} />);

            expect(screen.getByText(/^Temporal Information$/i)).toBeInTheDocument();
            expect(screen.getByText(/^Start$/i)).toBeInTheDocument();
            expect(screen.getByText(/^End$/i)).toBeInTheDocument();
        });

        test('hides labels when showLabels is false', () => {
            render(<TemporalInputs {...defaultProps} showLabels={false} />);

            expect(screen.queryByText(/^Temporal Information$/i)).not.toBeInTheDocument();
        });

        test('displays existing values', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '2024-12-31',
                startTime: '10:30:00',
                endTime: '15:45:00',
                timezone: 'Europe/Berlin',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.getByDisplayValue('2024-01-01')).toBeInTheDocument();
            expect(screen.getByDisplayValue('2024-12-31')).toBeInTheDocument();
            expect(screen.getByDisplayValue('10:30:00')).toBeInTheDocument();
            expect(screen.getByDisplayValue('15:45:00')).toBeInTheDocument();
        });
    });

    describe('User Input', () => {
        test('calls onChange when start date is entered', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const startDateInput = screen.getByLabelText(/^Date \(optional\)$/i, { selector: '#start-date' });

            await user.type(startDateInput, '2024-01-01');

            expect(mockOnChange).toHaveBeenCalledWith('startDate', '2024-01-01');
        });

        test('calls onChange when end date is entered', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const endDateInput = screen.getByLabelText(/^Date \(optional\)$/i, { selector: '#end-date' });

            await user.type(endDateInput, '2024-12-31');

            expect(mockOnChange).toHaveBeenCalledWith('endDate', '2024-12-31');
        });

        test('calls onChange when start time is entered', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const startTimeInput = screen.getByLabelText(/^Time \(optional\)$/i, { selector: '#start-time' }) as HTMLInputElement;

            await user.clear(startTimeInput);
            await user.type(startTimeInput, '1030');  // Type as 4 digits for time input

            // Check that onChange was called with the time value (may be '10:30' or partial during typing)
            expect(mockOnChange).toHaveBeenCalledWith('startTime', expect.stringContaining('10'));
        });

        test('calls onChange when end time is entered', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const endTimeInput = screen.getByLabelText(/^Time \(optional\)$/i, { selector: '#end-time' }) as HTMLInputElement;

            await user.clear(endTimeInput);
            await user.type(endTimeInput, '1545');  // Type as 4 digits for time input

            // Check that onChange was called with the time value
            expect(mockOnChange).toHaveBeenCalledWith('endTime', expect.stringContaining('15'));
        });

        test('calls onChange when timezone is selected', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const timezoneSelect = screen.getByRole('combobox');

            await user.click(timezoneSelect);

            const berlinOption = await screen.findByText(/Europe\/Berlin/);
            await user.click(berlinOption);

            expect(mockOnChange).toHaveBeenCalledWith('timezone', 'Europe/Berlin');
        });
    });

    describe('Timezone Selection', () => {
        test('displays UTC as default timezone', () => {
            render(<TemporalInputs {...defaultProps} />);

            // The selected value should be UTC
            expect(screen.getByText(/UTC \(Coordinated Universal Time\)/i)).toBeInTheDocument();
        });

        test('displays common timezone options', async () => {
            const user = userEvent.setup();
            render(<TemporalInputs {...defaultProps} />);

            const timezoneSelect = screen.getByRole('combobox');
            await user.click(timezoneSelect);

            // Check for some common timezones (use underscores as per IANA standard)
            expect(await screen.findByText(/Europe\/Berlin/)).toBeInTheDocument();
            expect(screen.getByText(/America\/New_York/)).toBeInTheDocument();
            expect(screen.getByText(/Asia\/Tokyo/)).toBeInTheDocument();
        });
    });

    describe('Date Validation', () => {
        test('shows error when start date is after end date', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-12-31',
                endDate: '2024-01-01',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.getByText(/start date must be before or equal to end date/i)).toBeInTheDocument();
        });

        test('allows same start and end date', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '2024-01-01',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/start date must be before/i)).not.toBeInTheDocument();
        });

        test('does not show error for valid date range', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '2024-12-31',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/start date must be before/i)).not.toBeInTheDocument();
        });
    });

    describe('Time Validation', () => {
        test('shows error for invalid time format', () => {
            const props = {
                ...defaultProps,
                startTime: '25:00',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.getByText(/time must be in HH:MM or HH:MM:SS format/i)).toBeInTheDocument();
        });

        test('accepts HH:MM format', () => {
            const props = {
                ...defaultProps,
                startTime: '10:30',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/time must be in HH:MM or HH:MM:SS format/i)).not.toBeInTheDocument();
        });

        test('accepts HH:MM:SS format', () => {
            const props = {
                ...defaultProps,
                startTime: '10:30:45',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/time must be in HH:MM or HH:MM:SS format/i)).not.toBeInTheDocument();
        });

        test('shows error when start time is after end time on same date', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '2024-01-01',
                startTime: '15:00',
                endTime: '10:00',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.getByText(/start time must be before end time when dates are the same/i)).toBeInTheDocument();
        });

        test('allows start time after end time on different dates', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '2024-01-02',
                startTime: '15:00',
                endTime: '10:00',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/start time must be before end time/i)).not.toBeInTheDocument();
        });

        test('accepts empty time values', () => {
            const props = {
                ...defaultProps,
                startTime: '',
                endTime: '',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/time must be in/i)).not.toBeInTheDocument();
        });
    });

    describe('Edge Cases', () => {
        test('handles midnight time correctly (00:00)', () => {
            const props = {
                ...defaultProps,
                startTime: '00:00',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/time must be in/i)).not.toBeInTheDocument();
        });

        test('handles end of day time correctly (23:59)', () => {
            const props = {
                ...defaultProps,
                endTime: '23:59:59',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/time must be in/i)).not.toBeInTheDocument();
        });

        test('allows only end date without start date', () => {
            const props = {
                ...defaultProps,
                startDate: '',
                endDate: '2024-12-31',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/start date must be before/i)).not.toBeInTheDocument();
        });

        test('allows only start date without end date', () => {
            const props = {
                ...defaultProps,
                startDate: '2024-01-01',
                endDate: '',
            };

            render(<TemporalInputs {...props} />);

            expect(screen.queryByText(/start date must be before/i)).not.toBeInTheDocument();
        });
    });
});
