import '@testing-library/jest-dom/vitest';

import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import CoordinateInputs from '@/components/curation/fields/spatial-temporal-coverage/CoordinateInputs';

describe('CoordinateInputs', () => {
    const mockOnChange = vi.fn();

    const defaultProps = {
        latMin: '',
        lonMin: '',
        latMax: '',
        lonMax: '',
        onChange: mockOnChange,
    };

    beforeEach(() => {
        mockOnChange.mockClear();
    });

    test('renders all coordinate input fields', () => {
        render(<CoordinateInputs {...defaultProps} />);

        // Use specific selectors since labels appear multiple times
        expect(screen.getByLabelText(/^Latitude \*$/i, { selector: '#lat-min' })).toBeInTheDocument();
        expect(screen.getByLabelText(/^Longitude \*$/i, { selector: '#lon-min' })).toBeInTheDocument();
    });

    test('displays values correctly', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="48.137154"
                lonMin="11.576124"
                latMax="48.150000"
                lonMax="11.600000"
            />,
        );

        expect(screen.getByDisplayValue('48.137154')).toBeInTheDocument();
        expect(screen.getByDisplayValue('11.576124')).toBeInTheDocument();
        expect(screen.getByDisplayValue('48.150000')).toBeInTheDocument();
        expect(screen.getByDisplayValue('11.600000')).toBeInTheDocument();
    });

    test('calls onChange when latitude min is changed', async () => {
        const user = userEvent.setup();
        render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = screen.getByLabelText(/^Latitude \*$/i, {
            selector: '#lat-min',
        });

        await user.clear(latMinInput);
        await user.type(latMinInput, '48.5');

        // user.type fires onChange for each character, so check the last call
        const calls = mockOnChange.mock.calls;
        const lastCall = calls[calls.length - 1];
        expect(lastCall[0]).toBe('latMin');
        expect(lastCall[1]).toBe('48.5');
    });

    test('formats coordinates to max 6 decimal places', async () => {
        const user = userEvent.setup();
        render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = screen.getByLabelText(/^Latitude \*$/i, {
            selector: '#lat-min',
        });

        await user.clear(latMinInput);
        await user.type(latMinInput, '48.1234567890');

        // Check that the value was truncated to 6 decimal places
        const calls = mockOnChange.mock.calls;
        const lastCall = calls[calls.length - 1];
        expect(lastCall[1]).toBe('48.123456');
    });

    test('shows error for latitude outside valid range', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="100"
                latMax=""
            />,
        );

        expect(
            screen.getByText(/Latitude must be between -90 and \+90/i),
        ).toBeInTheDocument();
    });

    test('shows error for longitude outside valid range', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                lonMin="200"
                lonMax=""
            />,
        );

        expect(
            screen.getByText(/Longitude must be between -180 and \+180/i),
        ).toBeInTheDocument();
    });

    test('shows error when min is greater than max', () => {
        render(
            <CoordinateInputs
                {...defaultProps}
                latMin="50"
                latMax="40"
                lonMin=""
                lonMax=""
            />,
        );

        expect(
            screen.getByText(/Min latitude cannot be greater than max latitude/i),
        ).toBeInTheDocument();
    });

    test('marks latitude min and longitude min as required', () => {
        render(<CoordinateInputs {...defaultProps} />);

        const latMinInput = screen.getByLabelText(/^Latitude \*$/i, {
            selector: '#lat-min',
        });
        const lonMinInput = screen.getByLabelText(/^Longitude \*$/i, {
            selector: '#lon-min',
        });

        expect(latMinInput).toBeRequired();
        expect(lonMinInput).toBeRequired();
    });

    test('latitude max and longitude max are optional', () => {
        render(<CoordinateInputs {...defaultProps} />);

        const latMaxInput = screen.getByLabelText(/^Latitude$/i, {
            selector: '#lat-max',
        });
        const lonMaxInput = screen.getByLabelText(/^Longitude$/i, {
            selector: '#lon-max',
        });

        expect(latMaxInput).not.toBeRequired();
        expect(lonMaxInput).not.toBeRequired();
    });

    test('displays section labels', () => {
        render(<CoordinateInputs {...defaultProps} />);

        expect(screen.getByText(/^Coordinates$/i)).toBeInTheDocument();
        expect(screen.getByText(/^Min \(Required\)$/i)).toBeInTheDocument();
        expect(screen.getByText(/^Max \(Optional\)$/i)).toBeInTheDocument();
    });
});
